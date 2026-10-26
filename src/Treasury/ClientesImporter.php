<?php
declare(strict_types=1);

namespace App\Treasury;

use App\Http\HttpException;

/**
 * Lê a relação cliente → banco (clientes_bancos.xlsx, folha "Export Worksheet"):
 *   BPCNUM_0 = código do cliente (= Terc. do Sage), BPCNAM_0 = nome, PAYBAN_0 = código do banco, DES_0 = nome do banco.
 * O código é tratado como texto, sem zeros à esquerda; a linha 0 (a própria empresa) não entra.
 * Não regista conteúdo de linhas em logs.
 */
final class ClientesImporter
{
    /** Nome do banco no ficheiro → [id, nome] do banco da página. */
    public const ALIAS = [
        'novo banco' => ['nb', 'Novo Banco'],
        'millennium bcp' => ['bcp', 'BCP'],
        'banco comercial portugues' => ['bcp', 'BCP'],
        'bcp' => ['bcp', 'BCP'],
        'banco santander totta' => ['san', 'Santander'],
        'santander totta' => ['san', 'Santander'],
        'santander' => ['san', 'Santander'],
        'caixa geral de depositos' => ['cgd', 'CGD'],
        'cgd' => ['cgd', 'CGD'],
        'bank millennium polska eur' => ['mpe', 'Bank Millennium Polska EUR'],
        'bank millennium polska pln' => ['mpp', 'Bank Millennium Polska PLN'],
    ];

    public static function normBank(string $s): string
    {
        $t = Util::lower($s);
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return preg_replace('/\s+/', ' ', $t) ?? $t;
    }

    public static function normCode(mixed $v): string
    {
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }
        $t = trim((string) $v);
        if (preg_match('/^\d+$/', $t) === 1) {
            $t = ltrim($t, '0');
        }
        return $t;
    }

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
            $book = $reader->load($path);
            $rows = null;
            foreach ($book->getAllSheets() as $sh) { // a folha com o cabeçalho BPCNUM_0 (por defeito "Export Worksheet")
                $r = $sh->toArray(null, false, false, false);
                foreach (array_slice($r, 0, 5) as $line) {
                    if (in_array('bpcnum_0', array_map(static fn ($c) => strtolower(trim((string) $c)), (array) $line), true)) {
                        $rows = $r;
                        break 2;
                    }
                }
            }
            if ($rows === null) {
                throw new \RuntimeException('cabecalho');
            }
        } catch (\Throwable $e) {
            error_log('Treasury clientes: leitura falhou (' . get_class($e) . ')');
            throw new HttpException(422, 'Não foi possível ler o ficheiro de clientes e bancos (.xlsx com as colunas BPCNUM_0, BPCNAM_0, PAYBAN_0, DES_0).');
        }
        $h = null;
        $col = [];
        foreach ($rows as $i => $line) {
            $low = array_map(static fn ($c) => strtolower(trim((string) $c)), (array) $line);
            if (in_array('bpcnum_0', $low, true)) {
                $h = $i;
                foreach ($low as $c => $name) {
                    $col[$name] = $c;
                }
                break;
            }
        }
        if ($h === null || !isset($col['bpcnum_0'], $col['des_0'])) {
            throw new HttpException(422, 'Formato inesperado: faltam as colunas BPCNUM_0 e DES_0.');
        }
        $map = [];
        $ignored = 0;
        $dups = 0;
        $n = 0;
        for ($i = $h + 1; $i < count($rows); $i++) {
            $line = $rows[$i];
            $code = self::normCode($line[$col['bpcnum_0']] ?? '');
            $bank = trim((string) ($line[$col['des_0']] ?? ''));
            if ($code === '' && $bank === '') {
                continue;
            }
            $n++;
            if ($code === '' || $code === '0' || $bank === '' || preg_match('/^[A-Za-z0-9._\-]{1,29}$/', $code) !== 1) {
                $ignored++; // linha 0 (a própria empresa), código ou banco em branco
                continue;
            }
            if (isset($map[$code]) && $map[$code] !== $bank) {
                $dups++;
            }
            $map[$code] = mb_substr($bank, 0, 100);
        }
        if ($map === []) {
            throw new HttpException(422, 'O ficheiro não contém clientes com banco.');
        }
        $warnings = [];
        if ($dups > 0) {
            $warnings[] = $dups . ' clientes repetidos com bancos diferentes: vale a última linha.';
        }
        $unknown = [];
        foreach (array_unique(array_values($map)) as $b) {
            if (!isset(self::ALIAS[self::normBank($b)])) {
                $unknown[] = $b;
            }
        }
        if ($unknown !== []) {
            $warnings[] = 'Bancos fora da lista conhecida (serão criados com saldo 0): ' . implode(', ', array_slice($unknown, 0, 10)) . '.';
        }
        return [
            'version' => 1, 'importedAt' => gmdate('c'), 'rows' => $n, 'ignored' => $ignored, 'warnings' => $warnings, 'map' => $map,
        ];
    }
}
