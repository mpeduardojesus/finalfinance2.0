<?php
declare(strict_types=1);

namespace App\Treasury;

/**
 * Motor de cálculo do Plano de tesouraria, portado do ecrã original (React/TSX):
 * entidades nos dois lados, movimentos recorrentes, projeção por banco (semanas ISO), banco sugerido para
 * pagamentos, dashboard e plano de sugestões. Funções puras: sem acesso a ficheiros nem registo de dados.
 */
final class Engine
{
    public const SEM = '__sem';
    public const OUT = 'Fora do Sage';
    public const SAGE = 'Sage X3';
    public const XS = 'Sage X3 · conta de cliente (compensação)';
    public const REC = 'Recorrente';
    public const CATS = ['Renda', 'Salários', 'Impostos/IVA/IRC/Seg. Social', 'Seguros', 'Outros'];
    public const FREQS = ['Semanal', 'Mensal', 'Trimestral', 'Anual'];
    public const FDS = ['Manter', 'Adiantar para dia útil', 'Adiar para dia útil'];
    public const WDN = ['Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado', 'Domingo'];
    public const MODOS = ['Sugerido', 'Fixo', 'Manual'];
    public const CRITS = ['Maior folga', 'Ordem de prioridade', 'Minimizar uso de CCC', 'Minimizar transferências'];
    public const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    public const BANDS = ['1–30 dias', '31–90 dias', '91–180 dias', '181–365 dias', 'mais de 365 dias'];

    // ------------------------------------------------------------------ utilitários

    public static function pkOf(array $it): string
    {
        return ($it['party'] ?? '') !== '' ? (string) $it['party'] : $it['tipo'] . ':' . Util::lower((string) $it['parte']);
    }

    public static function plannedOf(array $it): ?string
    {
        return (($it['dataPlan'] ?? '') !== '' ? Util::parseDate((string) $it['dataPlan']) : null) ?? Util::parseDate((string) $it['venc']);
    }

    public static function isRecIt(array $it): bool
    {
        return str_starts_with((string) $it['origem'], self::REC);
    }

    private static function lastDayOf(int $y, int $m): int
    {
        return (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));
    }

    /** @param array<string,mixed> $s */
    private static function startOf(array $s, string $ref): int
    {
        return Util::dayNum(Util::parseDate((string) ($s['inicio'] ?? '')) ?? $ref);
    }

    /**
     * Fim do horizonte: a data final indicada ou a derivada do horizonte em semanas.
     * @param array<string,mixed> $s @return array{0:int,1:int,2:bool} início, fim (dias) e se a data final é inválida
     */
    public static function calcEnd(array $s, string $ref): array
    {
        $startN = self::startOf($s, $ref);
        $nW = (int) ($s['horiz'] ?? 10) ?: 10;
        $vista = (string) ($s['vista'] ?? 'Semanal');
        $endN = $vista === 'Diária' ? $startN + min($nW, 12) * 7 - 1 : Util::mondayOf($startN) + 7 * $nW - 1;
        $fimErr = false;
        $fim = trim((string) ($s['fim'] ?? ''));
        if ($fim !== '') {
            $p = Util::parseDate($fim);
            if ($p !== null && Util::dayNum($p) >= $startN) {
                $endN = Util::dayNum($p);
            } else {
                $fimErr = true;
            }
        }
        if ($vista === 'Diária' && $endN > $startN + 399) {
            $endN = $startN + 399;
        }
        if ($endN > $startN + 1830) { // proteção do servidor: no máximo 5 anos
            $endN = $startN + 1830;
        }
        return [$startN, $endN, $fimErr];
    }

    /** Saldo mínimo da conta à ordem: vazio ou inválido = 5.000 €. @param array<string,mixed> $b */
    public static function minOf(array $b): float
    {
        $t = $b['minimo'] ?? '';
        if ($t === null || (is_string($t) && trim($t) === '')) {
            return 5000.0;
        }
        $v = Util::parseNum($t);
        return $v === null ? 5000.0 : max(0.0, (float) $v);
    }

    // ------------------------------------------------------------------ entidades nos dois lados

    /**
     * Terceiros com documentos Sage a receber e a pagar.
     * @param list<array<string,mixed>> $items @return list<array<string,mixed>>
     */
    public static function dual(array $items): array
    {
        $m = [];
        foreach ($items as $it) {
            if ($it['origem'] !== self::SAGE || ($it['party'] ?? '') === '') {
                continue;
            }
            $terc = substr((string) $it['party'], 1);
            if (!isset($m[$terc])) {
                $m[$terc] = ['terc' => $terc, 'nome' => $it['parte'], 'r' => 0.0, 'p' => 0.0, 'nR' => 0, 'nP' => 0, 'netR' => 0.0, 'netP' => 0.0];
            }
            $e = &$m[$terc];
            if ($it['tipo'] === 'R') {
                $e['r'] += abs((float) $it['valor']);
                $e['nR']++;
                $e['netR'] += (float) $it['valor'];
            } else {
                $e['p'] += abs((float) $it['valor']);
                $e['nP']++;
                $e['netP'] += (float) $it['valor'];
            }
            unset($e);
        }
        $arr = [];
        foreach ($m as $e) {
            if ($e['nR'] > 0 && $e['nP'] > 0) {
                $arr[] = $e;
            }
        }
        usort($arr, static fn ($a, $b) => ($b['r'] + $b['p']) <=> ($a['r'] + $a['p']));
        return $arr;
    }

    /** 'F' (fornecedor, a pagar pela diferença) ou 'C' (cliente, duas entradas). @param array<string,string> $classOv */
    public static function classOf(array $e, array $classOv): string
    {
        $o = $classOv[$e['terc']] ?? null;
        if ($o === 'F' || $o === 'C') {
            return $o;
        }
        return $e['p'] >= $e['r'] ? 'F' : 'C';
    }

    /** @param list<array<string,mixed>> $dual @param array<string,string> $classOv @return array<string,true> */
    public static function supSet(array $dual, array $classOv): array
    {
        $s = [];
        foreach ($dual as $e) {
            if (self::classOf($e, $classOv) === 'F') {
                $s[$e['terc']] = true;
            }
        }
        return $s;
    }

    // ------------------------------------------------------------------ movimentos recorrentes

    /**
     * Datas (número do dia) de um movimento recorrente entre $fromN e $toN, já com a regra de fim de semana.
     * @param array<string,mixed> $r @return list<int>
     */
    public static function expandRec(array $r, int $fromN, int $toN): array
    {
        $ini = Util::parseDate((string) ($r['ini'] ?? ''));
        if ($ini === null) {
            return [];
        }
        $fimS = trim((string) ($r['fim'] ?? '')) !== '' ? Util::parseDate((string) $r['fim']) : null;
        $iniN = Util::dayNum($ini);
        $fimN = $fimS !== null ? Util::dayNum($fimS) : 1000000000;
        $dia = (int) ($r['dia'] ?? 0);
        $fds = (string) ($r['fds'] ?? self::FDS[0]);
        $out = [];
        $adj = static function (int $n) use ($fds): int {
            $w = ($n + 3) % 7;
            if ($w < 5 || $fds === self::FDS[0]) {
                return $n;
            }
            return $fds === self::FDS[1] ? $n - ($w - 4) : $n + (7 - $w);
        };
        $push = static function (int $nom) use (&$out, $iniN, $fimN, $adj, $fromN, $toN): void {
            if ($nom < $iniN || $nom > $fimN) {
                return;
            }
            $a = $adj($nom);
            if ($a >= $fromN && $a <= $toN) {
                $out[] = $a;
            }
        };
        $freq = (string) ($r['freq'] ?? '');
        if ($freq === 'Semanal') {
            if (!($dia >= 0 && $dia <= 6)) {
                return [];
            }
            $n = $iniN + ((($dia - (($iniN + 3) % 7) + 7) % 7));
            if ($n < $fromN - 7) {
                $n += intdiv($fromN - 7 - $n, 7) * 7;
            }
            for (; $n <= $toN + 3 && $n <= $fimN; $n += 7) {
                $push($n);
            }
            return $out;
        }
        $step = $freq === 'Mensal' ? 1 : ($freq === 'Trimestral' ? 3 : ($freq === 'Anual' ? 12 : 0));
        if ($step === 0 || !($dia >= 1 && $dia <= 31)) {
            return [];
        }
        $y0 = (int) substr($ini, 0, 4);
        $m0 = (int) substr($ini, 5, 2);
        for ($k = 0; $k < 6000; $k++) {
            $mi = $m0 - 1 + $k * $step;
            $y = $y0 + intdiv($mi, 12);
            $m = ($mi % 12) + 1;
            $nom = Util::dayNum(sprintf('%04d-%02d-%02d', $y, $m, min($dia, self::lastDayOf($y, $m))));
            if ($nom > $toN + 3 || $nom > $fimN) {
                break;
            }
            $push($nom);
        }
        return $out;
    }

    /** @return array{0:int,1:int} início e fim (dias) do intervalo coberto pelo horizonte */
    public static function recRange(array $s, string $ref): array
    {
        [$startN, $endN] = self::calcEnd($s, $ref);
        return [$startN, $endN];
    }

    /** @param list<array<string,mixed>> $recs @param array<string,mixed> $s @return list<array<string,mixed>> */
    public static function recItems(array $recs, array $s, string $ref): array
    {
        $out = [];
        if (!(bool) ($s['incRec'] ?? true)) {
            return $out;
        }
        [$a, $b] = self::recRange($s, $ref);
        foreach ($recs as $r) {
            if (empty($r['ativo'])) {
                continue;
            }
            $v = Util::parseNum($r['valor'] ?? null);
            if ($v === null || $v <= 0) {
                continue;
            }
            foreach (self::expandRec($r, $a, $b) as $n) {
                $out[] = [
                    'id' => 'rc|' . $r['id'] . '|' . $n, 'tipo' => $r['tipo'], 'tdoc' => 'Rec',
                    'doc' => $r['cat'] . ' · ' . mb_strtolower((string) $r['freq'], 'UTF-8'),
                    'parte' => trim((string) $r['desc']) !== '' ? trim((string) $r['desc']) : $r['cat'], 'party' => '',
                    'venc' => Util::isoOf($n), 'valor' => round($v * 100) / 100, 'banco' => (string) ($r['banco'] ?? ''), 'dataPlan' => '',
                    'origem' => self::REC . ' · ' . $r['cat'], 'flag' => '', 'editable' => 'rec', 'excluded' => false, 'overridden' => false,
                ];
            }
        }
        return $out;
    }

    /**
     * Vista de trabalho: a conta de cliente de um fornecedor passa para os pagamentos, com sinal invertido.
     * @param list<array<string,mixed>> $items @param array<string,true> $sup @param list<array<string,mixed>> $rec @return list<array<string,mixed>>
     */
    public static function view(array $items, array $sup, array $rec): array
    {
        $out = [];
        foreach ($items as $it) {
            if ($it['origem'] === self::SAGE && $it['tipo'] === 'R' && ($it['party'] ?? '') !== '' && $it['party'][0] === 'C' && isset($sup[substr($it['party'], 1)])) {
                $it['tipo'] = 'P';
                $it['valor'] = -(float) $it['valor'];
                $it['party'] = 'F' . substr($it['party'], 1);
                $it['origem'] = self::XS;
                $it['editable'] = 'xs';
            }
            $out[] = $it;
        }
        return array_merge($out, $rec);
    }

    // ------------------------------------------------------------------ projeção

    /**
     * @param array<string,mixed> $in items, banks, partyBank, classOv, recs, prio, s, ref
     * @return array<string,mixed>
     */
    public static function compute(array $in): array
    {
        $items = $in['items'];
        $banks = $in['banks'];
        $partyBank = $in['partyBank'] ?? [];
        $classOv = $in['classOv'] ?? [];
        $recs = $in['recs'] ?? [];
        $s = $in['s'];
        $ref = (string) $in['ref'];

        $bankIds = [];
        foreach ($banks as $b) {
            $bankIds[(string) $b['id']] = true;
        }
        $defR = (string) ($s['defBankR'] ?? '');
        $defP = (string) ($s['defBankP'] ?? '');
        $modoP = (string) ($s['modoP'] ?? 'Sugerido');
        $crit = (string) ($s['crit'] ?? 'Maior folga');
        [$startN, $endN, $fimErr] = self::calcEnd($s, $ref);
        $vista = (string) ($s['vista'] ?? 'Semanal');
        $weekly = $vista === 'Semanal';
        $monthly = $vista === 'Mensal';
        $agrup = (bool) ($s['agrup'] ?? true);
        $diaPag = (string) ($s['diaPag'] ?? '4');
        $regraDia = (string) ($s['regraDia'] ?? 'ant');
        $repor = (bool) ($s['repor'] ?? true);
        $passo = $s['passo'] ?? '5000';
        $netting = (bool) ($s['netting'] ?? true);
        $useCCC = (bool) ($s['useCCC'] ?? true);
        $incPend = (bool) ($s['incPend'] ?? true);
        $incOut = (bool) ($s['incOut'] ?? true);
        $ruleR = (string) ($s['ruleR'] ?? '0');
        $ruleP = (string) ($s['ruleP'] ?? '0');

        $dual = self::dual($items);
        $sup = self::supSet($dual, $classOv);
        $recIt = self::recItems($recs, $s, $ref);
        $view = self::view($items, $sup, $recIt);

        $prioIds = [];
        foreach ((array) ($in['prio'] ?? []) as $id) {
            if (isset($bankIds[(string) $id]) && !in_array((string) $id, $prioIds, true)) {
                $prioIds[] = (string) $id;
            }
        }
        foreach ($banks as $b) {
            if (!in_array((string) $b['id'], $prioIds, true)) {
                $prioIds[] = (string) $b['id'];
            }
        }

        $bankOf = static function (array $it) use ($bankIds, $partyBank, $defR, $defP, $modoP): string {
            $b = (string) ($it['banco'] ?? '');
            if ($b !== '' && isset($bankIds[$b])) {
                return $b;
            }
            $pb = (string) ($partyBank[self::pkOf($it)] ?? '');
            if ($pb !== '' && isset($bankIds[$pb])) {
                return $pb;
            }
            $db = $it['tipo'] === 'R' ? $defR : ($modoP === 'Manual' ? '' : $defP);
            if ($db !== '' && isset($bankIds[$db])) {
                return $db;
            }
            return '';
        };

        $m0 = Util::mondayOf($startN);
        $periods = [];
        $wd = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
        if ($weekly) {
            for ($st = $m0; $st <= $endN; $st += 7) {
                $wk = sprintf('%02d', Util::isoWeek($st));
                $periods[] = [
                    'label' => 'S' . $wk . ' ' . Util::isoYear($st) . ' (' . Util::dm($st) . '–' . Util::dm($st + 6) . ')',
                    'short' => 'S' . $wk . (Util::isoYear($st) === Util::isoYear($m0) ? '' : '/' . substr((string) Util::isoYear($st), 2)),
                    'iso' => Util::isoWeekLabelN($st), 'start' => Util::isoOf($st),
                ];
            }
        } elseif ($monthly) {
            $mi0 = Util::monthIdx($startN);
            $mi1 = Util::monthIdx($endN);
            for ($mi = $mi0; $mi <= $mi1; $mi++) {
                $y = intdiv($mi, 12);
                $periods[] = [
                    'label' => self::MESES[$mi % 12] . ' ' . $y,
                    'short' => self::MESES[$mi % 12] . ($y === intdiv($mi0, 12) ? '' : '/' . substr((string) $y, 2)),
                    'iso' => '', 'start' => sprintf('%04d-%02d-01', $y, $mi % 12 + 1),
                ];
            }
        } else {
            for ($st = $startN; $st <= $endN; $st++) {
                $periods[] = ['label' => $wd[($st + 3) % 7] . ' ' . Util::dm($st), 'short' => Util::dm($st), 'iso' => Util::isoWeekLabelN($st), 'start' => Util::isoOf($st)];
            }
        }
        $np = count($periods);
        $mis = Util::monthIdx($startN);
        $idxOf = static function (int $n) use ($weekly, $monthly, $m0, $mis, $startN, $np, $endN): int {
            if ($n > $endN) {
                return -1;
            }
            $i = $weekly ? (int) floor(($n - $m0) / 7) : ($monthly ? Util::monthIdx($n) - $mis : $n - $startN);
            if ($i < 0) {
                $i = 0;
            }
            return $i >= $np ? -1 : $i;
        };

        $incl = array_values(array_filter($view, static fn (array $it): bool =>
            ($incPend || !str_contains((string) ($it['flag'] ?? ''), 'p'))
            && ($incOut || !str_starts_with((string) $it['origem'], self::OUT))));

        // compensação de créditos por entidade (mais antigos primeiro)
        $eff = [];
        $hasXs = false;
        foreach ($incl as $it) {
            if ($it['origem'] === self::XS) {
                $hasXs = true;
                break;
            }
        }
        if ($netting || $hasXs) {
            $groups = [];
            foreach ($incl as $it) {
                $groups[$it['tipo'] . '|' . self::pkOf($it)][] = $it;
            }
            $key = static function (array $it): int {
                $d = self::plannedOf($it);
                return $d !== null ? Util::dayNum($d) : 1000000000;
            };
            foreach ($groups as $list) {
                $deb = array_values(array_filter($list, static fn ($x) => $x['valor'] > 0));
                $cre = array_values(array_filter($list, static fn ($x) => $x['valor'] < 0 && ($netting || $x['origem'] === self::XS)));
                usort($deb, static fn ($a, $b) => $key($a) <=> $key($b));
                usort($cre, static fn ($a, $b) => $key($a) <=> $key($b));
                $dv = array_map(static fn ($x) => (float) $x['valor'], $deb);
                $cv = array_map(static fn ($x) => -(float) $x['valor'], $cre);
                $j = 0;
                $nd = count($dv);
                for ($i = 0, $nc = count($cv); $i < $nc; $i++) {
                    while ($cv[$i] > 0.004 && $j < $nd) {
                        $t = min($cv[$i], $dv[$j]);
                        $cv[$i] -= $t;
                        $dv[$j] -= $t;
                        if ($dv[$j] <= 0.004) {
                            $j++;
                        }
                    }
                }
                foreach ($deb as $i => $x) {
                    $eff[$x['id']] = $dv[$i];
                }
                foreach ($cre as $i => $x) {
                    $eff[$x['id']] = -$cv[$i];
                }
            }
        }

        $cols = [];
        foreach ($banks as $b) {
            $nm = trim((string) $b['nome']);
            $cols[] = [
                'id' => (string) $b['id'], 'nome' => $nm !== '' ? $nm : '(sem nome)', 'saldo' => (float) (Util::parseNum($b['saldo'] ?? null) ?? 0.0),
                'ccc' => (float) (Util::parseNum($b['ccc'] ?? null) ?? 0.0), 'minimo' => self::minOf($b),
            ];
        }
        $fora = 0;
        $foraV = 0.0;
        $invalid = 0;
        $vencFora = 0;
        $vencForaV = 0.0;
        $compens = 0;
        $ovR = 0.0;
        $ovP = 0.0;
        $evs = [];
        $pend = [];
        $sugMap = [];
        $grp = [];
        $payMap = [];
        $grpN = 0;
        $grpDocs = 0;
        $route = static function (array $e, string $b0) use (&$evs, &$pend, $modoP, $cols): void {
            if ($b0 === '' && $e['item']['tipo'] === 'P' && $modoP === 'Sugerido' && count($cols) > 0) {
                $pend[] = $e;
                return;
            }
            $evs[$b0 !== '' ? $b0 : self::SEM][] = $e;
        };
        foreach ($incl as $it) {
            $v = $eff[$it['id']] ?? (float) $it['valor'];
            $d = self::plannedOf($it);
            if ($d === null || !is_finite((float) $it['valor']) || (float) $it['valor'] == 0.0) {
                $invalid++;
                continue;
            }
            if (abs($v) < 0.005) {
                $compens++;
                continue;
            }
            $n0 = Util::dayNum($d);
            $n = $n0;
            $atraso = false;
            if ($n0 < $startN) {
                $atraso = true;
                if (($it['dataPlan'] ?? '') !== '' && Util::parseDate((string) $it['dataPlan']) !== null) {
                    $n = $startN;
                } else {
                    $rule = $it['tipo'] === 'R' ? $ruleR : $ruleP;
                    if ($rule === 'x') {
                        $vencFora++;
                        $vencForaV += $it['tipo'] === 'R' ? $v : -$v;
                        continue;
                    }
                    $n = $startN + (int) $rule;
                }
                if ($it['tipo'] === 'R') {
                    $ovR += $v;
                } else {
                    $ovP += $v;
                }
            }
            $p = $idxOf($n);
            if ($p < 0) {
                $fora++;
                $foraV += $v;
                continue;
            }
            $b0 = $bankOf($it);
            if ($agrup && $it['tipo'] === 'P' && ($it['dataPlan'] ?? '') === '' && !str_starts_with((string) $it['origem'], self::OUT) && !self::isRecIt($it)) {
                $gk = self::pkOf($it) . '|' . Util::isoYear($n) . '-' . sprintf('%02d', Util::isoWeek($n)) . '|' . $b0;
                if (!isset($grp[$gk])) {
                    $grp[$gk] = ['b0' => $b0, 'l' => []];
                }
                $grp[$gk]['l'][] = ['it' => $it, 'v' => $v, 'n' => $n, 'atraso' => $atraso];
                continue;
            }
            $route(['n' => $n, 'atraso' => $atraso, 'p' => $p, 'item' => $it, 'v' => $v, 'saldo' => 0.0, 'due' => $n], $b0);
        }
        // pagamentos agrupados por fornecedor e semana ISO, pagos no dia de pagamento (sexta-feira por defeito)
        $dow = ctype_digit($diaPag) && (int) $diaPag <= 4 ? (int) $diaPag : 4;
        $gkeys = array_map('strval', array_keys($grp));
        sort($gkeys, SORT_STRING);
        foreach ($gkeys as $gk) {
            $gg = $grp[$gk];
            $g = $gg['l'];
            usort($g, static fn ($a, $b) => ($a['n'] <=> $b['n']) ?: strcmp((string) $a['it']['id'], (string) $b['it']['id']));
            $tot = 0.0;
            foreach ($g as $x) {
                $tot += $x['v'];
            }
            $atr = false;
            foreach ($g as $x) {
                if ($x['atraso']) {
                    $atr = true;
                    break;
                }
            }
            $pd = Util::payDateOf($g[0]['n'], $atr, $startN, $dow, $regraDia);
            if ($pd > $endN) {
                foreach ($g as $x) {
                    $fora++;
                    $foraV += $x['v'];
                }
                continue;
            }
            if (abs($tot) < 0.005) {
                $compens += count($g);
                continue;
            }
            $item = $g[0]['it'];
            $mem = null;
            if (count($g) > 1) {
                $fl = '';
                $all = '';
                foreach ($g as $x) {
                    $all .= (string) ($x['it']['flag'] ?? '');
                }
                for ($q = 0, $ql = strlen($all); $q < $ql; $q++) {
                    if (strpos($fl, $all[$q]) === false) {
                        $fl .= $all[$q];
                    }
                }
                $vs = array_map(static fn ($x) => (string) $x['it']['venc'], $g);
                sort($vs, SORT_STRING);
                $sameBank = true;
                foreach ($g as $x) {
                    if ((string) ($x['it']['banco'] ?? '') !== (string) ($g[0]['it']['banco'] ?? '')) {
                        $sameBank = false;
                        break;
                    }
                }
                $anySage = false;
                foreach ($g as $x) {
                    if ($x['it']['origem'] === self::SAGE) {
                        $anySage = true;
                        break;
                    }
                }
                $item = [
                    'id' => 'gp|' . $gk, 'tipo' => 'P', 'tdoc' => 'Grp', 'doc' => count($g) . ' faturas', 'parte' => $g[0]['it']['parte'], 'party' => $g[0]['it']['party'] ?? '',
                    'venc' => $vs[0], 'valor' => $tot, 'banco' => $sameBank ? (string) ($g[0]['it']['banco'] ?? '') : '', 'dataPlan' => '',
                    'origem' => $anySage ? self::SAGE : $g[0]['it']['origem'], 'flag' => $fl,
                ];
                $mem = array_map(static fn ($x) => ['it' => $x['it'], 'v' => $x['v']], $g);
            }
            foreach ($g as $x) {
                $payMap[$x['it']['id']] = ['n' => $pd, 'gid' => $item['id']];
            }
            $grpN++;
            $grpDocs += count($g);
            $ev = ['n' => $pd, 'atraso' => $atr, 'p' => $idxOf($pd), 'item' => $item, 'v' => $tot, 'saldo' => 0.0, 'due' => $g[0]['n']];
            if ($mem !== null) {
                $ev['mem'] = $mem;
            }
            $route($ev, $gg['b0']);
        }

        // banco sugerido para os pagamentos sem banco: pelo vencimento (vencidos primeiro, maiores primeiro no mesmo dia)
        if (count($pend) > 0) {
            $D = $endN - $startN + 1;
            $st = [];
            foreach ($cols as $c) {
                $dl = array_fill(0, $D, 0.0);
                foreach ($evs[$c['id']] ?? [] as $e) {
                    $t = $e['n'] - $startN;
                    if ($t >= 0 && $t < $D) {
                        $dl[$t] += $e['item']['tipo'] === 'R' ? $e['v'] : -$e['v'];
                    }
                }
                $r = $c['saldo'];
                $bal = [];
                foreach ($dl as $x) {
                    $r += $x;
                    $bal[] = $r;
                }
                $st[] = ['id' => $c['id'], 'bal' => $bal, 'lim' => $useCCC ? $c['ccc'] : 0.0, 'mn' => $c['minimo'], 'on' => $c['saldo'] != 0.0 || count($evs[$c['id']] ?? []) > 0];
            }
            $ord = $pend;
            usort($ord, static fn ($a, $b) =>
                (($a['atraso'] === $b['atraso']) ? 0 : ($a['atraso'] ? -1 : 1))
                ?: ($a['n'] <=> $b['n'])
                ?: ($b['v'] <=> $a['v'])
                ?: strcmp((string) $a['item']['id'], (string) $b['item']['id']));
            $rank = [];
            foreach ($prioIds as $i => $id) {
                $rank[$id] = $i;
            }
            foreach ($ord as $e) {
                $d = $e['n'] - $startN;
                $cand = [];
                foreach ($st as $i => $sx) {
                    $m = $sx['bal'][$d];
                    for ($t = $d + 1; $t < $D; $t++) {
                        if ($sx['bal'][$t] < $m) {
                            $m = $sx['bal'][$t];
                        }
                    }
                    $cand[] = ['i' => $i, 'h0' => $m - $e['v'] - $sx['mn'], 'hL' => $m - $e['v'] - $sx['mn'] + $sx['lim']];
                }
                $fits0 = array_values(array_filter($cand, static fn ($x) => $x['h0'] >= -0.005));
                $fits = count($fits0) > 0 ? $fits0 : array_values(array_filter($cand, static fn ($x) => $x['hL'] >= -0.005));
                $kx = static fn (array $x): float => count($fits0) > 0 ? $x['h0'] : $x['hL'];
                $pick = $cand[0];
                if ($e['v'] <= 0) {
                    $act = array_values(array_filter($cand, static fn ($x) => $st[$x['i']]['on']));
                    $pool = count($act) > 0 ? $act : $cand;
                    $pick = $pool[0];
                    foreach ($pool as $x) {
                        if ($x['hL'] < $pick['hL']) {
                            $pick = $x;
                        }
                    }
                } elseif (count($fits) === 0) {
                    foreach ($cand as $x) {
                        if ($x['hL'] > $pick['hL']) {
                            $pick = $x;
                        }
                    }
                } else {
                    $pick = $fits[0];
                    foreach ($fits as $x) {
                        if ($crit === 'Ordem de prioridade') {
                            if (($rank[$st[$x['i']]['id']] ?? 1000000000) < ($rank[$st[$pick['i']]['id']] ?? 1000000000)) {
                                $pick = $x;
                            }
                        } elseif ($crit === 'Minimizar uso de CCC') {
                            if ($x['h0'] > $pick['h0']) {
                                $pick = $x;
                            }
                        } elseif ($crit === 'Minimizar transferências') {
                            if ($kx($x) < $kx($pick)) {
                                $pick = $x;
                            }
                        } elseif ($kx($x) > $kx($pick)) {
                            $pick = $x;
                        }
                    }
                }
                $sid = $st[$pick['i']]['id'];
                for ($t = $d; $t < $D; $t++) {
                    $st[$pick['i']]['bal'][$t] -= $e['v'];
                }
                $ok = $e['v'] <= 0 || $pick['hL'] >= -0.005;
                $cc = $pick['h0'] < -0.005 && $e['v'] > 0;
                $hd = count($fits0) > 0 && $e['v'] > 0 ? $pick['h0'] : $pick['hL'];
                $e['sug'] = ['head' => $hd, 'ok' => $ok, 'ccc' => $cc];
                $evs[$sid][] = $e;
                $sugMap[$e['item']['id']] = ['bank' => $sid, 'head' => $hd, 'ok' => $ok, 'ccc' => $cc];
                foreach ($e['mem'] ?? [] as $mm) {
                    $sugMap[$mm['it']['id']] = ['bank' => $sid, 'head' => $hd, 'ok' => $ok, 'ccc' => $cc];
                }
            }
        }
        if (isset($evs[self::SEM])) {
            $cols[] = ['id' => self::SEM, 'nome' => 'Sem banco atribuído', 'saldo' => 0.0, 'ccc' => 0.0, 'minimo' => 0.0];
        }

        // simulação diária por banco: recebimentos e créditos primeiro, depois pagamentos (maiores primeiro).
        // Pagamento que deixe a conta à ordem abaixo do mínimo: usa a CCC em múltiplos de $step (até ao limite livre).
        // No fim de cada dia com movimentos, havendo saldo acima do mínimo, repõe-se a CCC utilizada em múltiplos de $step.
        $stepN = Util::parseNum($passo);
        $step = $stepN !== null && $stepN > 0 ? (float) $stepN : 5000.0;
        $res = [];
        foreach ($cols as $c) {
            $list = $evs[$c['id']] ?? [];
            $cred = static fn (array $e): int => ($e['item']['tipo'] === 'R' || $e['v'] < 0) ? 0 : 1;
            usort($list, static fn (array $a, array $b): int => ($a['n'] <=> $b['n']) ?: ($cred($a) <=> $cred($b)) ?: ($b['v'] <=> $a['v']));
            $cap = $useCCC && $c['id'] !== self::SEM ? $c['ccc'] : 0.0;
            $mn = $c['minimo'];
            $rec = array_fill(0, $np, 0.0);
            $pag = array_fill(0, $np, 0.0);
            $cu = array_fill(0, $np, 0.0);
            $cr = array_fill(0, $np, 0.0);
            $shortP = array_fill(0, $np, 0.0);
            $balEnd = array_fill(0, $np, null);
            $usedEndRaw = array_fill(0, $np, 0.0);
            $seq = [];
            $bal = $c['saldo'];
            $used = 0.0;
            $usedMax = 0.0;
            $cuT = 0.0;
            $crT = 0.0;
            $cuN = 0;
            $crN = 0;
            $min = $c['saldo'];
            $need = 0.0;
            $first = null;
            $i = 0;
            $nl = count($list);
            while ($i < $nl) {
                $dn = $list[$i]['n'];
                $lastP = $list[$i]['p'];
                while ($i < $nl && $list[$i]['n'] === $dn) {
                    $k = $i;
                    $i++;
                    $e = &$list[$k];
                    $lastP = $e['p'];
                    $mv = null;
                    if ($e['item']['tipo'] === 'R') {
                        $bal += $e['v'];
                        $rec[$e['p']] += $e['v'];
                    } else {
                        $bal -= $e['v'];
                        $pag[$e['p']] += $e['v'];
                        if ($e['v'] > 0 && $bal < $mn - 0.005 && $cap - $used > 0.005) {
                            $kk = ceil(($mn - $bal - 1e-9) / $step) * $step;
                            $dr = min($kk, $cap - $used);
                            $bal += $dr;
                            $used += $dr;
                            $cu[$e['p']] += $dr;
                            $cuT += $dr;
                            $cuN++;
                            $e['cu'] = $dr;
                            if ($used > $usedMax) {
                                $usedMax = $used;
                            }
                            $mv = ['n' => $e['n'], 'p' => $e['p'], 'k' => 'U', 'v' => $dr, 'used' => $used, 'saldo' => $bal];
                        }
                        if ($e['v'] > 0 && $bal < $mn - 0.005) {
                            $sh = $mn - $bal;
                            if ($sh > $need) {
                                $need = $sh;
                            }
                            if ($first === null) {
                                $first = $e['n'];
                            }
                            if ($sh > $shortP[$e['p']]) {
                                $shortP[$e['p']] = $sh;
                            }
                        }
                    }
                    $e['saldo'] = $bal;
                    $e['ua'] = $used;
                    if ($bal < $min) {
                        $min = $bal;
                    }
                    $seq[] = ['n' => $e['n'], 'k' => $k];
                    if ($mv !== null) {
                        $seq[] = ['n' => $e['n'], 'm' => $mv];
                    }
                    unset($e);
                }
                if ($useCCC && $repor && $used > 0.005) {
                    $sur = $bal - $mn;
                    $r = $used < $step ? ($sur >= $used - 1e-9 ? $used : 0.0) : min($used, floor(($sur + 1e-9) / $step) * $step);
                    if ($r > 0.005) {
                        $bal -= $r;
                        $used -= $r;
                        $cr[$lastP] += $r;
                        $crT += $r;
                        $crN++;
                        $seq[] = ['n' => $dn, 'm' => ['n' => $dn, 'p' => $lastP, 'k' => 'R', 'v' => $r, 'used' => $used, 'saldo' => $bal]];
                    }
                }
                $balEnd[$lastP] = $bal;
                $usedEndRaw[$lastP] = $used;
            }
            $close = [];
            $usedEnd = [];
            $rb = $c['saldo'];
            $ru = 0.0;
            for ($k = 0; $k < $np; $k++) {
                if ($balEnd[$k] !== null) {
                    $rb = $balEnd[$k];
                    $ru = $usedEndRaw[$k];
                }
                $close[] = $rb;
                $usedEnd[] = $ru;
            }
            $sugN = 0;
            $sugT = 0.0;
            foreach ($list as $e) {
                if (isset($e['sug'])) {
                    $sugN++;
                    $sugT += $e['v'];
                }
            }
            $res[] = [
                'id' => $c['id'], 'nome' => $c['nome'], 'saldo' => $c['saldo'], 'ccc' => $c['ccc'], 'minimo' => $mn,
                'list' => $list, 'seq' => $seq, 'rec' => $rec, 'pag' => $pag, 'cu' => $cu, 'cr' => $cr, 'close' => $close, 'usedEnd' => $usedEnd, 'shortP' => $shortP,
                'min' => $min, 'first' => $first, 'need' => $need, 'recT' => array_sum($rec), 'pagT' => array_sum($pag), 'final' => $rb,
                'usedMax' => $usedMax, 'usedFim' => $ru, 'cuT' => $cuT, 'crT' => $crT, 'cuN' => $cuN, 'crN' => $crN, 'sugN' => $sugN, 'sugT' => $sugT,
            ];
        }
        $sugV = 0.0;
        $sugBadN = 0;
        $sugBadV = 0.0;
        $sugDocs = 0;
        foreach ($pend as $e) {
            $sugV += $e['v'];
            $sugDocs += isset($e['mem']) ? count($e['mem']) : 1;
            $sx = $sugMap[$e['item']['id']] ?? null;
            if ($sx !== null && !$sx['ok'] && $e['v'] > 0) {
                $sugBadN++;
                $sugBadV += $e['v'];
            }
        }
        return [
            'startN' => $startN, 'startIso' => Util::isoOf($startN), 'endN' => $endN, 'endIso' => Util::isoOf($endN), 'fimErr' => $fimErr, 'step' => $step,
            'periods' => $periods, 'res' => $res, 'eff' => $eff,
            'fora' => $fora, 'foraV' => $foraV, 'invalid' => $invalid, 'vencFora' => $vencFora, 'vencForaV' => $vencForaV,
            'compens' => $compens, 'ovR' => $ovR, 'ovP' => $ovP, 'sugMap' => $sugMap, 'sugN' => count($pend), 'sugV' => $sugV, 'sugDocs' => $sugDocs,
            'sugBadN' => $sugBadN, 'sugBadV' => $sugBadV, 'payMap' => $payMap, 'grpN' => $grpN, 'grpDocs' => $grpDocs,
            'view' => $view, 'dual' => $dual, 'sup' => $sup, 'recItems' => $recIt,
            'prioIds' => $prioIds, 'weekly' => $weekly, 'monthly' => $monthly, 'vista' => $vista,
        ];
    }

    /** Banco efetivo mostrado (o do documento/entidade/defeito, ou o sugerido). @param array<string,mixed> $proj */
    public static function bankEff(array $it, array $proj, callable $bankOf): string
    {
        $b = $bankOf($it);
        return $b !== '' ? $b : (string) ($proj['sugMap'][$it['id']]['bank'] ?? '');
    }

    // ------------------------------------------------------------------ resumo do ficheiro Sage

    /** @param array<string,mixed> $proj @return array<string,array<string,mixed>> */
    public static function sage(array $proj): array
    {
        $startN = $proj['startN'];
        $mk = static function (string $tipo, bool $soXs) use ($proj, $startN): array {
            $l = array_values(array_filter($proj['view'], static fn ($x) => $x['tipo'] === $tipo && ($soXs ? $x['origem'] === self::XS : ($x['origem'] === self::SAGE || $x['origem'] === self::XS))));
            $pos = array_values(array_filter($l, static fn ($x) => $x['valor'] > 0));
            $neg = array_values(array_filter($l, static fn ($x) => $x['valor'] < 0));
            $od = static function (array $x) use ($startN): bool {
                $d = self::plannedOf($x);
                return $d !== null && Util::dayNum($d) < $startN;
            };
            $sum = static fn (array $a): float => (float) array_sum(array_map(static fn ($x) => (float) $x['valor'], $a));
            return [
                'n' => count($l), 'nPos' => count($pos), 'pos' => $sum($pos), 'nNeg' => count($neg), 'neg' => $sum($neg), 'net' => $sum($l),
                'ovPos' => $sum(array_values(array_filter($pos, $od))), 'ovNeg' => $sum(array_values(array_filter($neg, $od))),
                'pend' => count(array_filter($l, static fn ($x) => str_contains((string) $x['flag'], 'p'))),
            ];
        };
        return ['R' => $mk('R', false), 'P' => $mk('P', false), 'X' => $mk('P', true)];
    }

    // ------------------------------------------------------------------ dashboard

    /**
     * @param array<string,mixed> $proj @param array<string,mixed> $s
     * @return list<array<string,mixed>> documentos em aberto (sem recorrentes)
     */
    public static function inclV(array $proj, array $s): array
    {
        $incPend = (bool) ($s['incPend'] ?? true);
        $incOut = (bool) ($s['incOut'] ?? true);
        return array_values(array_filter($proj['view'], static fn ($it) => !self::isRecIt($it)
            && ($incPend || !str_contains((string) $it['flag'], 'p'))
            && ($incOut || !str_starts_with((string) $it['origem'], self::OUT))));
    }

    /** @param array<string,mixed> $proj @param list<array<string,mixed>> $inclV @return array<string,mixed> */
    public static function dash(array $proj, array $inclV): array
    {
        $startN = $proj['startN'];
        $mkSide = static fn (): array => ['docs' => 0, 'tot' => 0.0, 'ovDocs' => 0, 'ov' => 0.0, 'ovOld' => 0.0, 'ovOldDocs' => 0, 'wDays' => 0.0, 'ovAgeW' => 0.0, 'aging' => [0.0, 0.0, 0.0, 0.0, 0.0], 'agingN' => [0, 0, 0, 0, 0]];
        $side = ['R' => $mkSide(), 'P' => $mkSide()];
        $ent = ['R' => [], 'P' => []];
        foreach ($inclV as $it) {
            $v = $proj['eff'][$it['id']] ?? (float) $it['valor'];
            $d = self::plannedOf($it);
            if ($d === null || !is_finite($v) || $v < 0.005) {
                continue;
            }
            $t = $it['tipo'];
            $dn = Util::dayNum($d);
            $age = $startN - $dn;
            $side[$t]['docs']++;
            $side[$t]['tot'] += $v;
            $side[$t]['wDays'] += ($dn - $startN) * $v;
            $k = self::pkOf($it);
            if (!isset($ent[$t][$k])) {
                $ent[$t][$k] = ['nome' => $it['parte'] !== '' ? $it['parte'] : '—', 'v' => 0.0, 'ov' => 0.0, 'ovNew' => 0.0, 'ovOld' => 0.0];
            }
            $ent[$t][$k]['v'] += $v;
            if ($age > 0) {
                $side[$t]['ovDocs']++;
                $side[$t]['ov'] += $v;
                $side[$t]['ovAgeW'] += $age * $v;
                $b = $age <= 30 ? 0 : ($age <= 90 ? 1 : ($age <= 180 ? 2 : ($age <= 365 ? 3 : 4)));
                $side[$t]['aging'][$b] += $v;
                $side[$t]['agingN'][$b]++;
                $ent[$t][$k]['ov'] += $v;
                if ($age > 365) {
                    $side[$t]['ovOld'] += $v;
                    $side[$t]['ovOldDocs']++;
                    $ent[$t][$k]['ovOld'] += $v;
                } else {
                    $ent[$t][$k]['ovNew'] += $v;
                }
            }
        }
        $rank = static function (array $m, string $by): array {
            $a = [];
            foreach ($m as $e) {
                if ($e[$by] > 0.005) {
                    $a[] = $e;
                }
            }
            usort($a, static fn ($x, $y) => $y[$by] <=> $x[$by]);
            return $a;
        };
        $np = count($proj['periods']);
        $dRec = [];
        $dPag = [];
        $dClose = [];
        for ($i = 0; $i < $np; $i++) {
            $dRec[] = array_sum(array_map(static fn ($r) => $r['rec'][$i], $proj['res']));
            $dPag[] = array_sum(array_map(static fn ($r) => $r['pag'][$i], $proj['res']));
            $dClose[] = array_sum(array_map(static fn ($r) => $r['close'][$i], $proj['res']));
        }
        $saldo0 = array_sum(array_column($proj['res'], 'saldo'));
        $minV = $saldo0;
        $minI = -1;
        foreach ($dClose as $i => $c) {
            if ($c < $minV) {
                $minV = $c;
                $minI = $i;
            }
        }
        $ccc = 0.0;
        foreach ($proj['res'] as $r) {
            if ($r['id'] !== self::SEM) {
                $ccc += $r['ccc'];
            }
        }
        $sm = static function (string $k) use ($proj): float {
            $t = 0.0;
            foreach ($proj['res'] as $r) {
                $t += $r[$k];
            }
            return $t;
        };
        return [
            'cuT' => $sm('cuT'), 'crT' => $sm('crT'), 'cuN' => (int) $sm('cuN'), 'crN' => (int) $sm('crN'), 'usedFim' => $sm('usedFim'), 'usedPk' => $sm('usedMax'),
            'side' => $side, 'dRec' => $dRec, 'dPag' => $dPag, 'dClose' => $dClose, 'saldo0' => $saldo0, 'minV' => $minV, 'minI' => $minI, 'ccc' => $ccc,
            'topR' => $rank($ent['R'], 'v'), 'topP' => $rank($ent['P'], 'v'), 'ovNewR' => $rank($ent['R'], 'ovNew'), 'ovOldR' => $rank($ent['R'], 'ovOld'), 'ovP' => $rank($ent['P'], 'ov'),
        ];
    }

    // ------------------------------------------------------------------ plano de sugestões de otimização

    /**
     * Só sugestões: nada é alterado automaticamente.
     * @param array<string,mixed> $proj @param array<string,mixed> $dash @param list<array<string,mixed>> $inclV @param array<string,mixed> $s
     * @return list<array{t:string,imp:float,why:string}>
     */
    public static function suggestions(array $proj, array $dash, array $inclV, array $s): array
    {
        $out = [];
        $startN = $proj['startN'];
        $useCCC = (bool) ($s['useCCC'] ?? true);
        $agrup = (bool) ($s['agrup'] ?? true);
        $netting = (bool) ($s['netting'] ?? true);
        $ruleR = (string) ($s['ruleR'] ?? '0');
        $ruleP = (string) ($s['ruleP'] ?? '0');
        $crit = (string) ($s['crit'] ?? 'Maior folga');
        $eur = [Util::class, 'eur'];
        $pct = [Util::class, 'pct'];
        $real = array_values(array_filter($proj['res'], static fn ($r) => $r['id'] !== self::SEM));
        $rawNeed = static fn (array $r): float => $r['need'];
        $firstNeg = static fn (array $r): int => $r['first'] !== null ? $r['first'] : $startN;
        $freeOf = static fn (array $r): float => $r['usedMax'] > 0.005 ? 0.0 : max(0.0, $r['min'] - $r['minimo']);
        $defs = array_values(array_filter($real, static fn ($r) => $rawNeed($r) > 0.5));
        usort($defs, static fn ($a, $b) => $rawNeed($b) <=> $rawNeed($a));
        $freeInit = [];
        foreach ($real as $r) {
            $freeInit[$r['id']] = $freeOf($r);
        }
        $donors = [];
        foreach ($real as $r) {
            if ($freeOf($r) > 0.5) {
                $donors[] = ['r' => $r, 'free' => $freeOf($r)];
            }
        }
        usort($donors, static fn ($a, $b) => $b['free'] <=> $a['free']);
        $rest = [];
        foreach ($defs as $d) {
            $need = $rawNeed($d);
            foreach ($donors as &$dn) {
                if ($need < 0.5) {
                    break;
                }
                if ($dn['r']['id'] === $d['id'] || $dn['free'] < 0.5) {
                    continue;
                }
                $t = min($need, $dn['free']);
                $dn['free'] -= $t;
                $need -= $t;
                $out[] = [
                    't' => 'Transferir ' . $eur($t) . ' de ' . $dn['r']['nome'] . ' para ' . $d['nome'] . ' até ' . Util::dm($firstNeg($d)),
                    'imp' => $t,
                    'why' => $dn['r']['nome'] . ' mantém um saldo nunca inferior a ' . $eur($dn['r']['min']) . ' (mínimo exigido ' . $eur($dn['r']['minimo']) . ') em todo o horizonte, sem usar a CCC, enquanto ' . $d['nome'] . ' fica abaixo do seu saldo mínimo a partir de ' . Util::dm($firstNeg($d)) . ', mesmo depois de usar a CCC (falta máxima ' . $eur($rawNeed($d)) . '). A transferência evita financiamento externo.',
                ];
            }
            unset($dn);
            $rest[$d['id']] = $need;
        }
        foreach ($defs as $d) {
            $need = $rest[$d['id']] ?? 0.0;
            if ($need < 0.5) {
                continue;
            }
            if (!$useCCC && $d['ccc'] > 0.5) {
                $out[] = [
                    't' => 'Ligar a utilização da CCC do ' . $d['nome'] . ' para cobrir a falta de ' . $eur($need),
                    'imp' => min($need, $d['ccc']),
                    'why' => 'Com Usar CCC quando falta saldo desligado, o ' . $d['nome'] . ' fica abaixo do saldo mínimo a partir de ' . Util::dm($firstNeg($d)) . ' (falta máxima ' . $eur($need) . '). Limite de CCC: ' . $eur($d['ccc']) . '. O custo de juros não está calculado.',
                ];
            } else {
                $out[] = [
                    't' => 'Financiamento adicional ou renegociação de prazos no ' . $d['nome'],
                    'imp' => $need,
                    'why' => 'Depois das transferências e de toda a CCC (' . $eur($d['ccc']) . '), faltam ' . $eur($need) . ' para o saldo mínimo de ' . $eur($d['minimo']) . ' a partir de ' . Util::dm($firstNeg($d)) . '. Opções: financiamento de curto prazo, antecipação de cobranças ou diferimento acordado de pagamentos.',
                ];
            }
        }
        if ($dash['cuT'] > 0.5) {
            $top = $real;
            usort($top, static fn ($a, $b) => $b['usedMax'] <=> $a['usedMax']);
            $top = $top[0] ?? null;
            $out[] = [
                't' => 'CCC: ' . (int) $dash['cuN'] . ' utilizações (' . $eur($dash['cuT']) . ') e ' . (int) $dash['crN'] . ' reposições (' . $eur($dash['crT']) . ') no horizonte' . ($dash['usedFim'] > 0.5 ? '; ficam ' . $eur($dash['usedFim']) . ' por repor' : ''),
                'imp' => $dash['usedPk'],
                'why' => 'A CCC cobre os pagamentos de sexta-feira que deixariam a conta à ordem abaixo do saldo mínimo, em múltiplos de ' . $eur($proj['step']) . ', e é reposta quando há saldo acima do mínimo. Pico utilizado (soma dos picos de cada banco): ' . $eur($dash['usedPk']) . ($top !== null && $top['usedMax'] > 0.5 ? ' (maior utilização num banco: ' . $top['nome'] . ', ' . $eur($top['usedMax']) . ')' : '') . '. Antecipar recebimentos ou transferir saldo de bancos com folga evita juros da CCC; o custo de juros não está calculado.',
            ];
        }
        if ($agrup && $proj['grpN'] > 0) {
            $gi = 0.0;
            foreach ($proj['res'] as $r) {
                $sr = 0.0;
                foreach ($r['list'] as $e) {
                    if (!isset($e['sug']) && $e['item']['tipo'] === 'P' && $e['v'] > 0 && isset($e['due']) && !self::isRecIt($e['item']) && !str_starts_with((string) $e['item']['origem'], self::OUT) && ($e['item']['dataPlan'] ?? '') === '') {
                        $sr += $e['v'];
                    }
                }
                $gi += $sr;
            }
            $out[] = [
                't' => 'Pagamentos agrupados por fornecedor e semana: ' . $proj['grpDocs'] . ' faturas em ' . $proj['grpN'] . ' pagamentos',
                'imp' => $gi + $proj['sugV'],
                'why' => 'Cada fornecedor recebe, no máximo, um pagamento por semana ISO, à sexta-feira (regra do dia de pagamento nos parâmetros), com os créditos compensados dentro do grupo. Reduz o número de transferências; o impacto é o valor agrupado.',
            ];
        }
        foreach (array_slice($defs, 0, 2) as $d) {
            if (($rest[$d['id']] ?? 0.0) < 0.5) {
                continue;
            }
            $cands = array_values(array_filter($real, static fn ($r) => $r['id'] !== $d['id'] && ($freeInit[$r['id']] ?? 0.0) > 0.5));
            usort($cands, static fn ($a, $b) => ($freeInit[$b['id']] ?? 0.0) <=> ($freeInit[$a['id']] ?? 0.0));
            $rec = $cands[0] ?? null;
            if ($rec === null) {
                continue;
            }
            $g = [];
            foreach ($d['list'] as $e) {
                if ($e['item']['tipo'] !== 'P' || $e['v'] <= 0 || str_starts_with((string) $e['item']['origem'], self::OUT) || self::isRecIt($e['item']) || ($e['item']['banco'] ?? '') !== '' || isset($e['sug'])) {
                    continue;
                }
                $k = self::pkOf($e['item']);
                if (isset($g[$k])) {
                    $g[$k]['v'] += $e['v'];
                } else {
                    $g[$k] = ['nome' => $e['item']['parte'] !== '' ? $e['item']['parte'] : '—', 'v' => $e['v']];
                }
            }
            $gv = array_values($g);
            usort($gv, static fn ($a, $b) => $b['v'] <=> $a['v']);
            foreach (array_slice($gv, 0, 2) as $t) {
                $fr = $freeInit[$rec['id']] ?? 0.0;
                $out[] = [
                    't' => 'Pagar ' . $t['nome'] . ' pelo ' . $rec['nome'] . ' em vez do ' . $d['nome'],
                    'imp' => min($t['v'], $fr),
                    'why' => 'Os pagamentos a esta entidade no horizonte somam ' . $eur($t['v']) . ' no ' . $d['nome'] . ', que está em falta. O ' . $rec['nome'] . ' é o banco com mais folga (saldo mínimo ' . $eur($fr) . '). Atribua o banco em Banco por entidade; o impacto é limitado à folga do banco de destino.',
                ];
            }
        }
        $sem = null;
        foreach ($proj['res'] as $r) {
            if ($r['id'] === self::SEM) {
                $sem = $r;
            }
        }
        if ($proj['sugN'] > 0) {
            $out[] = [
                't' => 'O motor de banco sugerido distribui ' . $proj['sugN'] . ' pagamentos a fornecedores pelos bancos (' . $eur($proj['sugV']) . ')',
                'imp' => $proj['sugV'],
                'why' => 'Os fornecedores não têm banco, por isso cada pagamento sem banco é atribuído ao banco que o consegue pagar (critério ' . mb_strtolower($crit, 'UTF-8') . ') e não é preciso atribuir bancos à mão.' . ($proj['sugBadN'] > 0 ? ' ' . $proj['sugBadN'] . ' pagamentos (' . $eur($proj['sugBadV']) . ') não cabem em nenhum banco: veja as transferências e a CCC abaixo.' : '') . ' Em Plano de pagamentos por banco pode aceitar a sugestão, para a converter em atribuições manuais.',
            ];
        }
        if ($sem !== null && $sem['pagT'] > 0.5) {
            $best = $real;
            usort($best, static fn ($a, $b) => ($b['final'] + ($useCCC ? $b['ccc'] : 0)) <=> ($a['final'] + ($useCCC ? $a['ccc'] : 0)));
            $best = $best[0] ?? null;
            $parties = [];
            foreach ($sem['list'] as $e) {
                if ($e['item']['tipo'] === 'P' && !self::isRecIt($e['item'])) {
                    $parties[self::pkOf($e['item'])] = true;
                }
            }
            $out[] = [
                't' => 'Atribuir banco de pagamento a ' . count($parties) . ' fornecedores sem banco (' . $eur($sem['pagT']) . ' em pagamentos)',
                'imp' => $sem['pagT'],
                'why' => 'Os fornecedores não têm banco associado: sem banco de pagamento, estes pagamentos não saem de nenhum saldo e o plano pode estar a esconder faltas de fundos. Escolha o banco de pagamento por defeito nos parâmetros ou um banco por fornecedor em Banco por entidade.' . ($best !== null ? ' O banco com mais folga no fim do horizonte é o ' . $best['nome'] . ' (' . $eur($best['final']) . '); se pagasse tudo isto terminaria perto de ' . $eur($best['final'] - $sem['pagT']) . ' (estimativa, sem calendário).' : ''),
            ];
        }
        if ($sem !== null && $sem['recT'] > 0.5) {
            $cl = [];
            foreach ($sem['list'] as $e) {
                if ($e['item']['tipo'] === 'R') {
                    $cl[self::pkOf($e['item'])] = true;
                }
            }
            $out[] = [
                't' => 'Confirmar o banco de recebimento de ' . count($cl) . ' clientes sem banco no ficheiro (' . $eur($sem['recT']) . ' em recebimentos)',
                'imp' => $sem['recT'],
                'why' => 'Estes clientes não constam do ficheiro de clientes e bancos, por isso os recebimentos não entram em nenhum saldo. Atribua o banco em Banco por entidade, ou escolha o banco de recebimento por defeito nos parâmetros.',
            ];
        }
        $pend = ['n' => 0, 'v' => 0.0];
        $ovPv = 0.0;
        $ovPn = 0;
        $totPv = 0.0;
        $byDay = [];
        $ovMap = [];
        foreach ($proj['res'] as $r) {
            foreach ($r['list'] as $e) {
                if ($e['item']['tipo'] !== 'P' || $e['v'] <= 0) {
                    continue;
                }
                $totPv += $e['v'];
                $byDay[$e['n']] = ($byDay[$e['n']] ?? 0.0) + $e['v'];
                if (str_contains((string) $e['item']['flag'], 'p')) {
                    $pend['n']++;
                    $pend['v'] += $e['v'];
                }
                if ($e['atraso'] && ($e['item']['dataPlan'] ?? '') === '' && !str_starts_with((string) $e['item']['origem'], self::OUT)) {
                    $ovPv += $e['v'];
                    $ovPn++;
                    $ok = self::pkOf($e['item']);
                    if (isset($ovMap[$ok])) {
                        $ovMap[$ok]['v'] += $e['v'];
                    } else {
                        $ovMap[$ok] = ['nome' => $e['item']['parte'] !== '' ? $e['item']['parte'] : '—', 'v' => $e['v']];
                    }
                }
            }
        }
        if ($pend['n'] > 0 && count($defs) > 0) {
            $out[] = [
                't' => 'Reter ' . $pend['n'] . ' pagamentos pendentes de aprovação até serem aprovados',
                'imp' => $pend['v'],
                'why' => 'São documentos de fornecedores em estado Pendente Aprovação, no valor de ' . $eur($pend['v']) . ', que só devem ser pagos depois de aprovados. Desligar Incluir pendentes de aprovação mostra o efeito no plano.',
            ];
        }
        if ($ovPv > 0.5 && $ruleP === '0') {
            $tops = array_values($ovMap);
            usort($tops, static fn ($x, $y) => $y['v'] <=> $x['v']);
            $tops = implode('; ', array_map(static fn ($e) => $e['nome'] . ' (' . $eur($e['v']) . ')', array_slice($tops, 0, 3)));
            $out[] = [
                't' => 'Escalonar ' . $ovPn . ' pagamentos vencidos que hoje caem todos no dia de início',
                'imp' => $ovPv,
                'why' => 'Documentos Sage vencidos que representam ' . $pct($ovPv, $totPv) . ' dos pagamentos do horizonte e estão todos planeados a ' . Util::dm($startN) . ($agrup ? ' (pagos na primeira sexta-feira)' : '') . '. Maiores: ' . $tops . '. Acorde datas com os principais fornecedores ou escolha outra regra em Pagamentos vencidos (+7, +14 ou +30 dias) para ver o efeito. Já estão fora do prazo: confirme juros de mora e risco de fornecimento antes de adiar.',
            ];
        }
        $early = 0.0;
        $earlyN = 0;
        foreach ($inclV as $it) {
            if ($it['tipo'] !== 'P' || ($it['dataPlan'] ?? '') === '') {
                continue;
            }
            $dp = Util::parseDate((string) $it['dataPlan']);
            $dv = Util::parseDate((string) $it['venc']);
            if ($dp !== null && $dv !== null && $dp < $dv && $it['valor'] > 0) {
                $early += $it['valor'];
                $earlyN++;
            }
        }
        if ($earlyN > 0) {
            $out[] = [
                't' => 'Repor no vencimento ' . $earlyN . ' pagamentos com data planeada antes do vencimento',
                'imp' => $early,
                'why' => 'Pagar no próprio vencimento não tem penalização e mantém a tesouraria até lá. Valor planeado antes do prazo: ' . $eur($early) . '.',
            ];
        }
        $R = $dash['side']['R'];
        $tops5 = array_slice($dash['ovNewR'], 0, 5);
        $sum5 = (float) array_sum(array_column($tops5, 'ovNew'));
        $recentOv = $R['ov'] - $R['ovOld'];
        if ($sum5 > 0.5) {
            $out[] = [
                't' => 'Priorizar a cobrança dos ' . count($tops5) . ' maiores clientes vencidos (menos de 1 ano)',
                'imp' => $sum5,
                'why' => implode('; ', array_map(static fn ($e) => $e['nome'] . ' ' . $eur($e['ovNew']), $tops5)) . '. Representam ' . $pct($sum5, $recentOv) . ' do vencido a receber com menos de 1 ano (' . $eur($recentOv) . ').' . ($ruleR === 'x' ? ' Com a regra atual estes valores estão fora do plano: cobrá-los é folga adicional.' : ' O plano já os conta ' . ($ruleR === '0' ? 'no dia de início' : 'com ' . $ruleR . ' dias de atraso') . ': se não entrarem, a falta de fundos é maior.'),
            ];
        }
        if ($R['ovOld'] > 0.5) {
            $o3 = implode('; ', array_map(static fn ($e) => $e['nome'] . ' ' . $eur($e['ovOld']), array_slice($dash['ovOldR'], 0, 3)));
            $out[] = [
                't' => 'Rever a cobrança de ' . $R['ovOldDocs'] . ' documentos de clientes vencidos há mais de 365 dias',
                'imp' => $R['ovOld'],
                'why' => 'Maiores: ' . $o3 . '. ' . ($ruleR === 'x' ? 'Estão fora do plano com a regra atual.' : 'Estão no plano como recebimento ' . ($ruleR === '0' ? 'no dia de início' : 'com ' . $ruleR . ' dias de atraso') . '; se a cobrança for duvidosa, a liquidez inicial está sobrestimada em ' . $eur($R['ovOld']) . '.') . ' Confirme com contabilidade e comercial, e considere provisão ou a regra Fora do plano.',
            ];
        }
        $credOf = static function (string $tipo) use ($inclV, $proj): array {
            $m = [];
            $tot = 0.0;
            $n = 0;
            foreach ($inclV as $it) {
                if ($it['tipo'] !== $tipo) {
                    continue;
                }
                $v = $proj['eff'][$it['id']] ?? (float) $it['valor'];
                if ($v >= -0.004) {
                    continue;
                }
                $tot += -$v;
                $n++;
                $k = self::pkOf($it);
                if (isset($m[$k])) {
                    $m[$k]['v'] += -$v;
                } else {
                    $m[$k] = ['nome' => $it['parte'] !== '' ? $it['parte'] : '—', 'v' => -$v];
                }
            }
            $top = array_values($m);
            usort($top, static fn ($a, $b) => $b['v'] <=> $a['v']);
            return ['tot' => $tot, 'n' => $n, 'top' => array_slice($top, 0, 3)];
        };
        $cp = $credOf('P');
        if ($cp['tot'] > 0.5) {
            $out[] = [
                't' => 'Utilizar ' . $cp['n'] . ' créditos de fornecedores por compensar',
                'imp' => $cp['tot'],
                'why' => 'Notas de crédito e pagamentos por regularizar que ainda não abatem a nenhuma fatura: ' . implode('; ', array_map(static fn ($e) => $e['nome'] . ' ' . $eur($e['v']), $cp['top'])) . '.' . ($netting ? ' Deduza-os nas próximas faturas ou peça o reembolso.' : ' A compensação está desligada: ligue-a para os abater às faturas da mesma entidade.'),
            ];
        }
        $cr = $credOf('R');
        if ($cr['tot'] > 0.5) {
            $out[] = [
                't' => 'Regularizar ' . $cr['n'] . ' recebimentos e notas de crédito de clientes por afetar',
                'imp' => $cr['tot'],
                'why' => 'Créditos de clientes sem fatura correspondente: ' . implode('; ', array_map(static fn ($e) => $e['nome'] . ' ' . $eur($e['v']), $cr['top'])) . '. Afetá-los às faturas abertas (ou confirmar que são adiantamentos) corrige o vencido a receber e a cobrança.',
            ];
        }
        $peakN = 0;
        $peakV = 0.0;
        foreach ($byDay as $n => $v) {
            if ($v > $peakV) {
                $peakV = $v;
                $peakN = $n;
            }
        }
        $avg = count($byDay) > 0 ? $totPv / count($byDay) : 0.0;
        if (!$agrup && count($byDay) > 2 && $totPv > 0 && $peakV >= 2.5 * $avg && $peakV >= 0.1 * $totPv) {
            $out[] = [
                't' => 'Distribuir os pagamentos concentrados em ' . Util::fmtD(Util::isoOf($peakN)),
                'imp' => $peakV - $avg,
                'why' => $eur($peakV) . ' num só dia, ' . $pct($peakV, $totPv) . ' dos pagamentos do horizonte (média diária ' . $eur($avg) . '). O impacto é o excesso sobre a média.' . ($peakN === $startN && $ruleP === '0' ? ' Coincide com os vencidos a pagar planeados no dia de início.' : ''),
            ];
        }
        usort($out, static fn ($a, $b) => $b['imp'] <=> $a['imp']);
        return $out;
    }
}
