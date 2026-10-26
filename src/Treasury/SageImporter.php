<?php
declare(strict_types=1);

namespace App\Treasury;

use App\Http\HttpException;

/**
 * Lê o mapa de itens em aberto do Sage X3 (.xls/.xlsx) com PhpSpreadsheet.
 * Colunas (por nome do cabeçalho): Terc., Nome, Tipo, Documento, Data venc., Colect., Valor a pagar, [Status], [Divisa].
 * Regras: Colect. CGMN/CGUE/CGPT = cliente (a receber); FGMN/FGUE/FGPT = fornecedor (a pagar, sinal invertido).
 * Não regista conteúdo de linhas em logs; os avisos só têm contagens e códigos de conta coletiva.
 */
final class SageImporter
{
    private const CUSTOMER = ['CGMN', 'CGUE', 'CGPT'];
    private const SUPPLIER = ['FGMN', 'FGUE', 'FGPT'];

    /** @return array<string,mixed> */
    public static function parse(string $path): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new HttpException(500, 'Dependências em falta (PhpSpreadsheet). Execute "composer install".');
        }
        require_once $autoload;

        try {
            $type = \PhpOffice\PhpSpreadsheet\IOFactory::identify($path);
            if (!in_array($type, ['Xls', 'Xlsx'], true)) {
                throw new \RuntimeException('tipo');
            }
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
            $rows = $sheet->toArray(null, false, false, false);
        } catch (\Throwable $e) {
            error_log('Treasury import: leitura falhou (' . get_class($e) . ')');
            throw new HttpException(422, 'Não foi possível ler o ficheiro. Envie o mapa de itens em aberto do Sage X3 em formato .xls ou .xlsx.');
        }

        // Cabeçalho: procurar a célula "Terc." nas primeiras linhas (no ficheiro Sage a linha 1 tem também Sociedade/Data ref.).
        $hRow = null;
        $off = 0;
        for ($r = 0; $r < min(10, count($rows)); $r++) {
            foreach ($rows[$r] as $c => $v) {
                if (self::norm($v) === 'terc') {
                    $hRow = $r;
                    $off = (int) $c;
                    break 2;
                }
            }
        }
        if ($hRow === null) {
            throw new HttpException(422, 'Formato inesperado: não encontrei a coluna "Terc." no cabeçalho.');
        }
        $col = [];
        foreach ($rows[$hRow] as $c => $v) {
            $k = self::norm($v);
            if ($k !== '' && $c >= $off && !isset($col[$k])) {
                $col[$k] = (int) $c - $off;
            }
        }
        foreach (['terc' => 'Terc.', 'nome' => 'Nome', 'tipo' => 'Tipo', 'documento' => 'Documento', 'datavenc' => 'Data venc.', 'colect' => 'Colect.', 'valorapagar' => 'Valor a pagar'] as $k => $label) {
            if (!isset($col[$k])) {
                throw new HttpException(422, 'Formato inesperado: falta a coluna "' . $label . '".');
            }
        }

        // Data de referência: célula a seguir a "Data ref."
        $ref = null;
        foreach ($rows[$hRow] as $c => $v) {
            if (str_starts_with(self::norm($v), 'dataref')) {
                $ref = self::toIso($rows[$hRow][$c + 1] ?? null);
                break;
            }
        }
        $warnings = [];
        if ($ref === null) {
            $ref = gmdate('Y-m-d');
            $warnings[] = 'Data de referência não encontrada no ficheiro; usada a data de hoje.';
        }

        $items = [];
        $seen = [];
        $skipped = 0;
        $badDate = 0;
        $badValue = 0;
        $unknownColect = [];
        $ignoredColect = [];
        $n = count($rows);
        for ($r = $hRow + 1; $r < $n; $r++) {
            $row = $rows[$r];
            $get = static fn (string $k): mixed => isset($col[$k]) ? ($row[$col[$k]] ?? null) : null;
            $terc = self::str($get('terc'));
            $doc = self::str($get('documento'));
            if ($terc === '' && $doc === '') {
                continue; // linha em branco
            }
            if ($terc === '' || $doc === '') {
                $skipped++;
                continue;
            }
            $colect = strtoupper(self::str($get('colect')));
            if (in_array($colect, self::CUSTOMER, true)) {
                $tipo = 'R';
            } elseif (in_array($colect, self::SUPPLIER, true)) {
                $tipo = 'P';
            } elseif ($colect !== '' && ($colect[0] === 'C' || $colect[0] === 'F')) {
                $tipo = $colect[0] === 'C' ? 'R' : 'P';
                $unknownColect[$colect] = true;
            } else {
                $ignoredColect[$colect] = ($ignoredColect[$colect] ?? 0) + 1;
                continue;
            }
            $venc = self::toIso($get('datavenc'));
            if ($venc === null) {
                $badDate++;
                continue;
            }
            $amt = Util::parseNum($get('valorapagar'));
            if ($amt === null) {
                $badValue++;
                continue;
            }
            $status = self::str($get('status'));
            $divisa = strtoupper(self::str($get('divisa')));
            $flag = '';
            if ($status !== '' && stripos($status, 'pend') === 0) {
                $flag .= 'p';
            }
            if ($divisa !== '' && $divisa !== 'EUR') {
                $flag .= 'u';
            }
            $tdoc = self::str($get('tipo'));
            $party = ($tipo === 'R' ? 'C' : 'F') . $terc;
            $base = $tipo . '|' . $party . '|' . $tdoc . '|' . $doc;
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $items[] = [
                'id' => 's' . substr(sha1($base . '|' . $seen[$base]), 0, 12),
                'tipo' => $tipo,
                'tdoc' => $tdoc,
                'doc' => $doc,
                'parte' => self::str($get('nome')),
                'party' => $party,
                'venc' => $venc,
                // Cliente: valor a receber tal como no Sage; fornecedor: sinal invertido (a pagar > 0).
                'valor' => $tipo === 'R' ? $amt : -$amt,
                'flag' => $flag,
            ];
        }
        if ($items === []) {
            throw new HttpException(422, 'O ficheiro não contém documentos em aberto reconhecidos.');
        }
        if ($skipped > 0) {
            $warnings[] = $skipped . ' linhas sem terceiro ou documento foram ignoradas.';
        }
        if ($badDate > 0) {
            $warnings[] = $badDate . ' linhas sem data de vencimento válida foram ignoradas.';
        }
        if ($badValue > 0) {
            $warnings[] = $badValue . ' linhas sem "Valor a pagar" numérico foram ignoradas.';
        }
        if ($unknownColect !== []) {
            $warnings[] = 'Contas coletivas desconhecidas classificadas pela 1.ª letra (C = cliente, F = fornecedor): ' . implode(', ', array_keys($unknownColect)) . '.';
        }
        foreach ($ignoredColect as $k => $cnt) {
            $warnings[] = $cnt . ' linhas com conta coletiva "' . $k . '" ignoradas (não é cliente nem fornecedor).';
        }

        return [
            'version' => 1,
            'ref' => $ref,
            'importedAt' => gmdate('c'),
            'sourceName' => basename($path),
            'sourceMtime' => @filemtime($path) ?: 0,
            'sourceSize' => @filesize($path) ?: 0,
            'rows' => count($items),
            'warnings' => $warnings,
            'items' => $items,
        ];
    }

    private static function str(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return trim((string) $v);
    }

    private static function norm(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $s = mb_strtolower(trim($v), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'º' => '', 'ª' => '']);
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /** Número de série Excel, texto de data ou DateTime → AAAA-MM-DD. */
    private static function toIso(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            if ($v < 20000 || $v > 80000) {
                return null;
            }
            return gmdate('Y-m-d', (int) (floor((float) $v) - 25569) * 86400);
        }
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '' && is_numeric($t)) {
                return self::toIso((float) $t);
            }
            return Util::parseDate($t);
        }
        return null;
    }
}
