<?php
declare(strict_types=1);

namespace App\Treasury;

/** Funções utilitárias (datas, números) portadas do ecrã original "Plano de tesouraria". */
final class Util
{
    /** Número em formato PT/EN ("1.234,56", "1,234.56", "(12,50)", "12 €"); null se inválido. */
    public static function parseNum(mixed $s): ?float
    {
        if (is_int($s) || is_float($s)) {
            return is_finite((float) $s) ? (float) $s : null;
        }
        if (!is_string($s)) {
            return null;
        }
        $t = preg_replace('/[\s\x{00a0}€]/u', '', $s) ?? '';
        if ($t === '') {
            return null;
        }
        $neg = false;
        if (preg_match('/^\(.*\)$/', $t) === 1) {
            $neg = true;
            $t = substr($t, 1, -1);
        }
        $hasC = str_contains($t, ',');
        $hasD = str_contains($t, '.');
        if ($hasC && $hasD) {
            if (strrpos($t, ',') > strrpos($t, '.')) {
                $t = str_replace(',', '.', str_replace('.', '', $t));
            } else {
                $t = str_replace(',', '', $t);
            }
        } elseif ($hasC) {
            $t = preg_replace('/,/', '.', $t, 1) ?? $t;
        } elseif ($hasD && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $t) === 1) {
            $t = str_replace('.', '', $t);
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $t) !== 1) {
            return null;
        }
        $n = (float) $t;
        if (!is_finite($n)) {
            return null;
        }
        return $neg ? -$n : $n;
    }

    /** AAAA-MM-DD ou DD/MM/AAAA (também . e -, ano com 2 dígitos); devolve AAAA-MM-DD ou null. */
    public static function parseDate(mixed $s): ?string
    {
        if (!is_string($s)) {
            return null;
        }
        $t = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $m) === 1) {
            return self::validYmd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $t, $m) === 1) {
            return self::validYmd((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return null;
    }

    public static function validYmd(int $y, int $m, int $d): ?string
    {
        if ($y < 100) {
            $y += 2000;
        }
        if ($y < 1900 || $y > 2200 || !checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    public static function dayNum(string $iso): int
    {
        return intdiv(gmmktime(0, 0, 0, (int) substr($iso, 5, 2), (int) substr($iso, 8, 2), (int) substr($iso, 0, 4)), 86400);
    }

    public static function isoOf(int $n): string
    {
        return gmdate('Y-m-d', $n * 86400);
    }

    /** Segunda-feira da semana (dias desde 1970-01-01 ocorreu a uma quinta-feira). */
    public static function mondayOf(int $n): int
    {
        return $n - (($n + 3) % 7);
    }

    public static function isoWeek(int $n): int
    {
        $thu = self::mondayOf($n) + 3;
        $y = (int) gmdate('Y', $thu * 86400);
        $jan1 = self::dayNum(sprintf('%04d-01-01', $y));
        return intdiv($thu - $jan1, 7) + 1;
    }

    /** Ano ISO 8601 (o do dia de quinta-feira da semana) de um número de dia. */
    public static function isoYear(int $n): int
    {
        return (int) gmdate('Y', (self::mondayOf($n) + 3) * 86400);
    }

    /** "AAAA-Www" (ISO 8601: semanas de segunda a domingo; a semana 1 contém a 1.ª quinta-feira do ano). */
    public static function isoWeekLabelN(int $n): string
    {
        return sprintf('%04d-W%02d', self::isoYear($n), self::isoWeek($n));
    }

    /** Semana ISO ("AAAA-Www") de uma data AAAA-MM-DD; '' se inválida/vazia. */
    public static function isoWeekLabel(?string $iso): string
    {
        $d = $iso !== null && $iso !== '' ? self::parseDate($iso) : null;
        return $d === null ? '' : self::isoWeekLabelN(self::dayNum($d));
    }

    /** DD/MM de um número de dia. */
    public static function dm(int $n): string
    {
        $s = self::isoOf($n);
        return substr($s, 8, 2) . '/' . substr($s, 5, 2);
    }

    /** Número com vírgula decimal e sem separador de milhares (formato de importação/exportação). */
    public static function fmtIn(float $n): string
    {
        return number_format($n, 2, ',', '');
    }

    /** Data ISO → DD/MM/AAAA */
    public static function fmtD(string $iso): string
    {
        return strlen($iso) === 10 ? substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4) : $iso;
    }

    /** Euros no formato pt-PT do ecrã original (1234,56 € / 12 345,67 €, separador de milhares = espaço duro a partir de 5 dígitos). */
    public static function eur(float $n): string
    {
        $r = round($n, 2);
        $s = number_format(abs($r), 2, ',', "\u{00a0}");
        $int = explode(',', $s)[0];
        if (strlen(str_replace("\u{00a0}", '', $int)) < 5) {
            $s = number_format(abs($r), 2, ',', '');
        }
        return ($r < 0 ? '-' : '') . $s . ' €';
    }

    /** Percentagem com 1 casa decimal no máximo (pt-PT); '—' se o total não for positivo. */
    public static function pct(float $a, float $b): string
    {
        if (!($b > 0)) {
            return '—';
        }
        $v = round(100 * $a / $b, 1);
        $s = rtrim(rtrim(number_format(abs($v), 1, ',', ''), '0'), ',');
        $int = explode(',', $s)[0];
        if (strlen($int) >= 5) {
            $s = number_format(abs($v), 1, ',', "\u{00a0}");
            $s = rtrim(rtrim($s, '0'), ',');
        }
        return ($v < 0 ? '-' : '') . $s . ' %';
    }

    public static function lower(string $s): string
    {
        return mb_strtolower(trim($s), 'UTF-8');
    }

    public static function csvQ(string $s): string
    {
        return preg_match('/[;"\n\t]/', $s) === 1 ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }

    /** @return list<string> */
    public static function splitLine(string $line, string $delim): array
    {
        $out = [];
        $cur = '';
        $inQ = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($inQ) {
                if ($ch === '"') {
                    if (($line[$i + 1] ?? '') === '"') {
                        $cur .= '"';
                        $i++;
                    } else {
                        $inQ = false;
                    }
                } else {
                    $cur .= $ch;
                }
            } elseif ($ch === '"') {
                $inQ = true;
            } elseif ($ch === $delim) {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $out[] = $cur;
        return array_map('trim', $out);
    }

    public static function detectDelim(string $line): string
    {
        return str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : ',');
    }

    /** Comparação numérica com sinal (-1/0/1), sem truncar floats (ao contrário de `return $a - $b` em usort). */
    public static function cmp(float|int $a, float|int $b): int
    {
        return $a <=> $b;
    }
}
