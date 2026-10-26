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

    private static function horiz(array $s): int
    {
        return (int) ($s['horiz'] ?? 10) ?: 10;
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
        $startN = self::startOf($s, $ref);
        $nW = self::horiz($s);
        $weekly = ($s['vista'] ?? 'Semanal') === 'Semanal';
        $endN = $weekly ? Util::mondayOf($startN) + 7 * $nW - 1 : $startN + min($nW, 12) * 7 - 1;
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
        $startN = self::startOf($s, $ref);
        $nW = self::horiz($s);
        $weekly = ($s['vista'] ?? 'Semanal') === 'Semanal';
        $netting = (bool) ($s['netting'] ?? true);
        $useCCC = (bool) ($s['useCCC'] ?? false);
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
            for ($k = 0; $k < $nW; $k++) {
                $st = $m0 + 7 * $k;
                $wk = sprintf('%02d', Util::isoWeek($st));
                $periods[] = [
                    'label' => 'S' . $wk . ' ' . Util::isoYear($st) . ' (' . Util::dm($st) . '–' . Util::dm($st + 6) . ')',
                    'short' => 'S' . $wk . (Util::isoYear($st) === Util::isoYear($m0) ? '' : '/' . substr((string) Util::isoYear($st), 2)),
                    'iso' => Util::isoWeekLabelN($st), 'start' => Util::isoOf($st),
                ];
            }
        } else {
            for ($k = 0; $k < min($nW, 12) * 7; $k++) {
                $st = $startN + $k;
                $periods[] = ['label' => $wd[($st + 3) % 7] . ' ' . Util::dm($st), 'short' => Util::dm($st), 'iso' => Util::isoWeekLabelN($st), 'start' => Util::isoOf($st)];
            }
        }
        $np = count($periods);
        $idxOf = static function (int $n) use ($weekly, $m0, $startN, $np): int {
            $i = $weekly ? (int) floor(($n - $m0) / 7) : $n - $startN;
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
            $cols[] = ['id' => (string) $b['id'], 'nome' => $nm !== '' ? $nm : '(sem nome)', 'saldo' => (float) $b['saldo'], 'ccc' => (float) $b['ccc']];
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
        foreach ($incl as $it) {
            $v = $eff[$it['id']] ?? (float) $it['valor'];
            $d = self::plannedOf($it);
            if ($d === null || (float) $it['valor'] == 0.0) {
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
            if ($b0 === '' && $it['tipo'] === 'P' && $modoP === 'Sugerido' && count($cols) > 0) {
                $pend[] = ['n' => $n, 'atraso' => $atraso, 'p' => $p, 'item' => $it, 'v' => $v, 'saldo' => 0.0];
                continue;
            }
            $evs[$b0 !== '' ? $b0 : self::SEM][] = ['n' => $n, 'atraso' => $atraso, 'p' => $p, 'item' => $it, 'v' => $v, 'saldo' => 0.0];
        }

        // banco sugerido para os pagamentos sem banco
        if (count($pend) > 0) {
            $D = $weekly ? $m0 + 7 * $np - $startN : $np;
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
                $st[] = ['id' => $c['id'], 'bal' => $bal, 'lim' => $useCCC ? $c['ccc'] : 0.0, 'on' => $c['saldo'] != 0.0 || count($evs[$c['id']] ?? []) > 0];
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
                    $cand[] = ['i' => $i, 'h0' => $m - $e['v'], 'hL' => $m - $e['v'] + $sx['lim']];
                }
                $fits = array_values(array_filter($cand, static fn ($x) => $x['hL'] >= -0.005));
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
                            if ($x['hL'] < $pick['hL']) {
                                $pick = $x;
                            }
                        } elseif ($x['hL'] > $pick['hL']) {
                            $pick = $x;
                        }
                    }
                }
                $sid = $st[$pick['i']]['id'];
                for ($t = $d; $t < $D; $t++) {
                    $st[$pick['i']]['bal'][$t] -= $e['v'];
                }
                $ok = $e['v'] <= 0 || $pick['hL'] >= -0.005;
                $e['sug'] = ['head' => $pick['hL'], 'ok' => $ok];
                $evs[$sid][] = $e;
                $sugMap[$e['item']['id']] = ['bank' => $sid, 'head' => $pick['hL'], 'ok' => $ok];
            }
        }
        if (isset($evs[self::SEM])) {
            $cols[] = ['id' => self::SEM, 'nome' => 'Sem banco atribuído', 'saldo' => 0.0, 'ccc' => 0.0];
        }
        $res = [];
        foreach ($cols as $c) {
            $list = $evs[$c['id']] ?? [];
            $cred = static fn (array $e): int => ($e['item']['tipo'] === 'R' || $e['v'] < 0) ? 0 : 1;
            usort($list, static fn (array $a, array $b): int => ($a['n'] <=> $b['n']) ?: ($cred($a) <=> $cred($b)) ?: ($b['v'] <=> $a['v']));
            $lim = $useCCC ? $c['ccc'] : 0.0;
            $run = $c['saldo'];
            $min = $c['saldo'];
            $first = $c['saldo'] + $lim < 0 ? $startN : null;
            $rec = array_fill(0, $np, 0.0);
            $pag = array_fill(0, $np, 0.0);
            $sugN = 0;
            $sugT = 0.0;
            foreach ($list as &$e) {
                if ($e['item']['tipo'] === 'R') {
                    $run += $e['v'];
                    $rec[$e['p']] += $e['v'];
                } else {
                    $run -= $e['v'];
                    $pag[$e['p']] += $e['v'];
                }
                $e['saldo'] = $run;
                if ($run < $min) {
                    $min = $run;
                }
                if ($first === null && $run + $lim < 0) {
                    $first = $e['n'];
                }
                if (isset($e['sug'])) {
                    $sugN++;
                    $sugT += $e['v'];
                }
            }
            unset($e);
            $close = [];
            $r = $c['saldo'];
            for ($i = 0; $i < $np; $i++) {
                $r += $rec[$i] - $pag[$i];
                $close[] = $r;
            }
            $res[] = [
                'id' => $c['id'], 'nome' => $c['nome'], 'saldo' => $c['saldo'], 'ccc' => $c['ccc'], 'lim' => $lim,
                'list' => $list, 'rec' => $rec, 'pag' => $pag, 'close' => $close, 'min' => $min, 'first' => $first,
                'need' => max(0.0, -($min + $lim)), 'recT' => array_sum($rec), 'pagT' => array_sum($pag), 'final' => $r,
                'sugN' => $sugN, 'sugT' => $sugT,
            ];
        }
        $sugV = 0.0;
        $sugBadN = 0;
        $sugBadV = 0.0;
        foreach ($pend as $e) {
            $sugV += $e['v'];
            $sx = $sugMap[$e['item']['id']] ?? null;
            if ($sx !== null && !$sx['ok'] && $e['v'] > 0) {
                $sugBadN++;
                $sugBadV += $e['v'];
            }
        }
        return [
            'startN' => $startN, 'startIso' => Util::isoOf($startN), 'periods' => $periods, 'res' => $res, 'eff' => $eff,
            'fora' => $fora, 'foraV' => $foraV, 'invalid' => $invalid, 'vencFora' => $vencFora, 'vencForaV' => $vencForaV,
            'compens' => $compens, 'ovR' => $ovR, 'ovP' => $ovP, 'sugMap' => $sugMap, 'sugN' => count($pend), 'sugV' => $sugV,
            'sugBadN' => $sugBadN, 'sugBadV' => $sugBadV, 'view' => $view, 'dual' => $dual, 'sup' => $sup, 'recItems' => $recIt,
            'prioIds' => $prioIds, 'weekly' => $weekly,
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
        return [
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
        $useCCC = (bool) ($s['useCCC'] ?? false);
        $netting = (bool) ($s['netting'] ?? true);
        $ruleR = (string) ($s['ruleR'] ?? '0');
        $ruleP = (string) ($s['ruleP'] ?? '0');
        $crit = (string) ($s['crit'] ?? 'Maior folga');
        $eur = [Util::class, 'eur'];
        $pct = [Util::class, 'pct'];
        $real = array_values(array_filter($proj['res'], static fn ($r) => $r['id'] !== self::SEM));
        $rawNeed = static fn (array $r): float => max(0.0, -$r['min']);
        $firstNeg = static function (array $r) use ($startN): int {
            if ($r['saldo'] < 0) {
                return $startN;
            }
            foreach ($r['list'] as $x) {
                if ($x['saldo'] < 0) {
                    return $x['n'];
                }
            }
            return $startN;
        };
        $defs = array_values(array_filter($real, static fn ($r) => $rawNeed($r) > 0.5));
        usort($defs, static fn ($a, $b) => $rawNeed($b) <=> $rawNeed($a));
        $freeInit = [];
        foreach ($real as $r) {
            $freeInit[$r['id']] = max(0.0, $r['min']);
        }
        $donors = [];
        foreach ($real as $r) {
            if ($r['min'] > 0.5) {
                $donors[] = ['r' => $r, 'free' => $r['min']];
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
                    'why' => $dn['r']['nome'] . ' mantém um saldo nunca inferior a ' . $eur($dn['r']['min']) . ' em todo o horizonte, enquanto ' . $d['nome'] . ' fica negativo a partir de ' . Util::dm($firstNeg($d)) . ' (necessidade máxima ' . $eur($rawNeed($d)) . '). A transferência evita recorrer à CCC ou a financiamento.',
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
            $cov = min($need, $d['ccc']);
            if ($cov > 0.5) {
                $out[] = [
                    't' => ($useCCC ? 'Manter a CCC do ' : 'Usar a CCC do ') . $d['nome'] . ' para cobrir o pico de ' . $eur($need),
                    'imp' => $cov,
                    'why' => 'Depois das transferências, o ' . $d['nome'] . ' ainda precisa de ' . $eur($need) . ' a partir de ' . Util::dm($firstNeg($d)) . '. Limite de CCC disponível: ' . $eur($d['ccc']) . ($need > $d['ccc'] ? '; ficam por cobrir ' . $eur($need - $d['ccc']) . ' mesmo com todo o limite' : '; cobre o pico por completo') . '. O custo de juros não está calculado.',
                ];
            }
            if ($need > $d['ccc'] + 0.5) {
                $out[] = [
                    't' => 'Financiamento adicional ou renegociação de prazos no ' . $d['nome'],
                    'imp' => $need - $d['ccc'],
                    'why' => 'Depois das transferências e de toda a CCC (' . $eur($d['ccc']) . '), faltam ' . $eur($need - $d['ccc']) . ' a partir de ' . Util::dm($firstNeg($d)) . '. Opções: financiamento de curto prazo, antecipação de cobranças ou diferimento acordado de pagamentos.',
                ];
            }
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
                'why' => 'Documentos Sage vencidos que representam ' . $pct($ovPv, $totPv) . ' dos pagamentos do horizonte e estão todos planeados a ' . Util::dm($startN) . '. Maiores: ' . $tops . '. Acorde datas com os principais fornecedores ou escolha outra regra em Pagamentos vencidos (+7, +14 ou +30 dias) para ver o efeito. Já estão fora do prazo: confirme juros de mora e risco de fornecimento antes de adiar.',
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
        if (count($byDay) > 2 && $totPv > 0 && $peakV >= 2.5 * $avg && $peakV >= 0.1 * $totPv) {
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
