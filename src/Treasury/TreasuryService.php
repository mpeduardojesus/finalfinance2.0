<?php
declare(strict_types=1);

namespace App\Treasury;

use App\Http\HttpException;

/**
 * Serviço do Plano de tesouraria: contexto (Sage + estado privado), leitura para a API (plano, dashboard, sugestões,
 * documentos, entidades), escrita (definições, bancos, entradas manuais, recorrentes, atribuições) e importação/exportação.
 * O cálculo vive em {@see Engine}.
 */
final class TreasuryService
{
    public const SEM = Engine::SEM;
    public const OUT = Engine::OUT;
    public const SAGE = Engine::SAGE;
    public const PAGE = 30;
    public const PP = 50;
    private const HORIZONS = ['4', '6', '8', '10', '12', '16', '20', '26'];

    /** @var array<string,mixed>|null */
    private ?array $ctx = null;
    /** @var array<string,mixed>|null */
    private ?array $projCache = null;

    public function __construct(private readonly Storage $store)
    {
    }

    // ------------------------------------------------------------------ contexto

    /** @return array<string,mixed> */
    public function context(bool $fresh = false): array
    {
        if ($this->ctx !== null && !$fresh) {
            return $this->ctx;
        }
        $sage = $this->store->loadSage();
        $demo = !empty($sage['demo']);
        $cli = $this->store->loadClientes($demo);
        $state = $this->store->loadState($demo);
        if ($state['cliSig'] === null && $cli['map'] !== []) { // primeira carga: semear o banco dos clientes a partir do ficheiro
            $sig = self::cliSig($cli);
            $this->store->mutateState($demo, function (array $st) use ($cli, $sig): array {
                if ($st['cliSig'] === null) {
                    [$st] = $this->seedClientes($st, $cli);
                    $st['cliSig'] = $sig;
                }
                return [$st, null];
            });
            $state = $this->store->loadState($demo);
        }
        $overrides = $state['overrides'];
        $items = [];
        $excluded = [];
        foreach ($sage['items'] as $it) {
            $it['origem'] = self::SAGE;
            $it['dataPlan'] = '';
            $it['banco'] = '';
            $it['editable'] = 'sage';
            $it['overridden'] = false;
            $it['excluded'] = false;
            $ov = $overrides[$it['id']] ?? null;
            if (is_array($ov)) {
                foreach (['banco', 'dataPlan', 'venc', 'valor'] as $k) {
                    if (array_key_exists($k, $ov)) {
                        $it[$k] = $ov[$k];
                        $it['overridden'] = true;
                    }
                }
                if (!empty($ov['excluded'])) {
                    $it['excluded'] = true;
                    $it['overridden'] = true;
                }
            }
            if ($it['excluded']) {
                $excluded[] = $it;
            } else {
                $items[] = $it;
            }
        }
        foreach ($state['manual'] as $m) {
            $m += ['tdoc' => 'Man', 'doc' => '', 'parte' => '', 'party' => '', 'banco' => '', 'dataPlan' => '', 'origem' => 'Manual', 'flag' => ''];
            $m['editable'] = 'manual';
            $m['overridden'] = false;
            $m['excluded'] = false;
            $items[] = $m;
        }
        $ref = (string) ($sage['ref'] ?? gmdate('Y-m-d'));
        $banks = [];
        foreach ($state['banks'] as $b) {
            $banks[] = ['id' => (string) $b['id'], 'nome' => (string) $b['nome'], 'saldo' => (float) ($b['saldo'] ?? 0), 'ccc' => (float) ($b['ccc'] ?? 0)];
        }
        $this->projCache = null;
        return $this->ctx = [
            'demo' => $demo, 'sage' => $sage, 'state' => $state, 'items' => $items, 'excluded' => $excluded, 'cli' => $cli,
            'ref' => $ref, 'banks' => $banks, 'settings' => $state['settings'], 'partyBank' => $state['partyBank'],
        ];
    }

    private function invalidate(): void
    {
        $this->ctx = null;
        $this->projCache = null;
    }

    /** @param array<string,mixed> $cli */
    private static function cliSig(array $cli): string
    {
        return substr(hash('sha256', (string) ($cli['sourceName'] ?? '') . '|' . (string) ($cli['importedAt'] ?? '') . '|' . count((array) $cli['map'])), 0, 16);
    }

    /**
     * Carrega o banco dos clientes (chave "C"+código) a partir do ficheiro; cria os bancos que faltem (saldo 0).
     * @param array<string,mixed> $st @param array<string,mixed> $cli @return array{0:array<string,mixed>,1:int,2:int} estado, clientes, bancos criados
     */
    private function seedClientes(array $st, array $cli): array
    {
        $banks = $st['banks'];
        $created = 0;
        $resolve = function (string $name) use (&$banks, &$created): string {
            $alias = ClientesImporter::ALIAS[ClientesImporter::normBank($name)] ?? null;
            foreach ($banks as $b) {
                if ($alias !== null && (string) $b['id'] === $alias[0]) {
                    return (string) $b['id'];
                }
            }
            foreach ($banks as $b) {
                if (Util::lower((string) $b['nome']) === Util::lower($alias[1] ?? $name)) {
                    return (string) $b['id'];
                }
            }
            $nb = ['id' => $alias[0] ?? 'b' . bin2hex(random_bytes(4)), 'nome' => $alias[1] ?? mb_substr($name, 0, 100), 'saldo' => 0.0, 'ccc' => 0.0];
            $banks[] = $nb;
            $created++;
            return $nb['id'];
        };
        $pb = [];
        foreach ($st['partyBank'] as $k => $v) {
            if (!str_starts_with((string) $k, 'C')) {
                $pb[$k] = $v;
            }
        }
        $cache = [];
        $n = 0;
        foreach ((array) $cli['map'] as $code => $bank) {
            $cache[$bank] ??= $resolve((string) $bank);
            $pb['C' . $code] = $cache[$bank];
            $n++;
        }
        $st['banks'] = $banks;
        $st['partyBank'] = $pb;
        return [$st, $n, $created];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function settingsOf(array $c): array
    {
        return $c['settings'] + ['ref' => $c['ref']];
    }

    /** Projeção completa (cálculo do motor sobre o contexto). @param array<string,mixed> $c @return array<string,mixed> */
    private function projFor(array $c): array
    {
        if ($this->projCache !== null) { // sempre chamada com o contexto atual (invalidate() limpa ambos)
            return $this->projCache;
        }
        $proj = Engine::compute([
            'items' => $c['items'], 'banks' => $c['banks'], 'partyBank' => $c['partyBank'], 'classOv' => $c['state']['classOv'],
            'recs' => $c['state']['recs'], 'prio' => $c['state']['prio'], 's' => $c['settings'], 'ref' => $c['ref'],
        ]);
        $this->projCache = $proj;
        return $proj;
    }

    /** Banco do documento sem sugestão ('' se nenhum). @param array<string,mixed> $c */
    private function bankOfFn(array $c): \Closure
    {
        $ids = array_fill_keys(array_column($c['banks'], 'id'), true);
        $pbk = $c['partyBank'];
        $s = $c['settings'];
        return static function (array $it) use ($ids, $pbk, $s): string {
            $b = (string) ($it['banco'] ?? '');
            if ($b !== '' && isset($ids[$b])) {
                return $b;
            }
            $pb = (string) ($pbk[Engine::pkOf($it)] ?? '');
            if ($pb !== '' && isset($ids[$pb])) {
                return $pb;
            }
            $db = $it['tipo'] === 'R' ? (string) $s['defBankR'] : ($s['modoP'] === 'Manual' ? '' : (string) $s['defBankP']);
            return ($db !== '' && isset($ids[$db])) ? $db : '';
        };
    }

    /** Texto da nota do documento (igual ao ecrã original). @param array<string,mixed> $it */
    public static function notaOf(array $it): string
    {
        $a = [];
        if (str_contains((string) $it['flag'], 'p')) {
            $a[] = 'Pendente aprovação';
        }
        if (str_contains((string) $it['flag'], 'u')) {
            $a[] = 'USD, sem conversão';
        }
        if ($it['origem'] !== self::SAGE) {
            $a[] = (string) $it['origem'];
        }
        return $a === [] ? '—' : implode(' · ', $a);
    }

    // ------------------------------------------------------------------ leitura (API)

    /** @return array<string,mixed> */
    public function state(): array
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        $sage = $c['sage'];
        return [
            'demo' => $c['demo'],
            'ref' => $c['ref'],
            'importedAt' => $sage['importedAt'] ?? null,
            'sourceName' => $c['demo'] ? null : ($sage['sourceName'] ?? null),
            'rows' => $sage['rows'] ?? count($sage['items']),
            'warnings' => $sage['warnings'] ?? [],
            'settings' => $c['settings'],
            'startValid' => (string) $c['settings']['inicio'] === '' || Util::parseDate((string) $c['settings']['inicio']) !== null,
            'banks' => $c['banks'],
            'prio' => $proj['prioIds'],
            'sageSummary' => Engine::sage($proj),
            'supCount' => count($proj['sup']),
            'dual' => $this->dualRows($c, $proj),
            'clientes' => [
                'source' => $c['cli']['source'], 'sourceName' => $c['cli']['sourceName'] ?? null, 'importedAt' => $c['cli']['importedAt'] ?? null,
                'rows' => $c['cli']['rows'] ?? 0, 'ignored' => $c['cli']['ignored'] ?? 0, 'count' => count((array) $c['cli']['map']), 'warnings' => $c['cli']['warnings'] ?? [],
            ],
            'counts' => [
                'excluidos' => count($c['excluded']),
                'pendentes' => count(array_filter($c['items'], fn ($x) => $x['origem'] === self::SAGE && str_contains((string) $x['flag'], 'p'))),
                'usd' => count(array_filter($c['items'], fn ($x) => $x['origem'] === self::SAGE && str_contains((string) $x['flag'], 'u'))),
            ],
        ];
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $proj @return list<array<string,mixed>> */
    private function dualRows(array $c, array $proj): array
    {
        $ids = array_fill_keys(array_column($c['banks'], 'id'), true);
        $names = array_column($c['banks'], 'nome', 'id');
        $out = [];
        foreach ($proj['dual'] as $e) {
            $f = Engine::classOf($e, $c['state']['classOv']) === 'F';
            $pb = (string) ($c['partyBank']['C' . $e['terc']] ?? '');
            $diff = $e['netP'] - $e['netR'];
            $out[] = [
                'terc' => $e['terc'], 'nome' => $e['nome'], 'nR' => $e['nR'], 'netR' => $e['netR'], 'nP' => $e['nP'], 'netP' => $e['netP'],
                'banco' => ($pb !== '' && isset($ids[$pb])) ? (string) $names[$pb] : '', 'classe' => $f ? 'F' : 'C', 'manual' => isset($c['state']['classOv'][$e['terc']]),
                'diff' => $diff,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        $res = [];
        foreach ($proj['res'] as $r) {
            $status = $r['need'] > 0 ? 'falta' : ($r['min'] < 0 ? 'ccc' : 'ok');
            $res[] = [
                'id' => $r['id'], 'nome' => $r['nome'], 'saldo' => $r['saldo'], 'ccc' => $r['ccc'], 'lim' => $r['lim'],
                'rec' => $r['rec'], 'pag' => $r['pag'], 'close' => $r['close'], 'min' => $r['min'],
                'first' => $r['first'] !== null ? Util::isoOf($r['first']) : null,
                'need' => $r['need'], 'recT' => $r['recT'], 'pagT' => $r['pagT'], 'final' => $r['final'],
                'sugN' => $r['sugN'], 'sugT' => $r['sugT'],
                'nEventos' => count($r['list']), 'estado' => $status,
                'visivel' => $r['saldo'] != 0.0 || count($r['list']) > 0,
            ];
        }
        $sel = $this->selectBank($proj['res'], '');
        $s = $c['settings'];
        return [
            'start' => $proj['startIso'],
            'ref' => $c['ref'],
            'periods' => $proj['periods'],
            'banks' => $res,
            'defaultBank' => $sel['id'] ?? '',
            'fora' => $proj['fora'], 'foraV' => $proj['foraV'], 'invalid' => $proj['invalid'],
            'vencFora' => $proj['vencFora'], 'vencForaV' => $proj['vencForaV'], 'compens' => $proj['compens'],
            'ovR' => $proj['ovR'], 'ovP' => $proj['ovP'],
            'recTot' => array_sum(array_column($res, 'recT')), 'pagTot' => array_sum(array_column($res, 'pagT')),
            'sugerido' => ['n' => $proj['sugN'], 'v' => $proj['sugV'], 'badN' => $proj['sugBadN'], 'badV' => $proj['sugBadV']],
            'modoP' => $s['modoP'], 'crit' => $s['crit'], 'prio' => array_map(static fn ($id) => ['id' => $id, 'nome' => (string) (array_column($c['banks'], 'nome', 'id')[$id] ?? $id)], $proj['prioIds']),
            'recorrentes' => ['nP' => count(array_filter($proj['recItems'], fn ($x) => $x['tipo'] === 'P')), 'vP' => array_sum(array_map(fn ($x) => $x['valor'], array_filter($proj['recItems'], fn ($x) => $x['tipo'] === 'P'))),
                'nR' => count(array_filter($proj['recItems'], fn ($x) => $x['tipo'] === 'R')), 'vR' => array_sum(array_map(fn ($x) => $x['valor'], array_filter($proj['recItems'], fn ($x) => $x['tipo'] === 'R')))],
        ];
    }

    /** @param list<array<string,mixed>> $res @return array<string,mixed>|null */
    private function selectBank(array $res, string $id): ?array
    {
        foreach ($res as $r) {
            if ($id !== '' && $r['id'] === $id) {
                return $r;
            }
        }
        foreach ($res as $r) {
            if ($r['need'] > 0 && $r['id'] !== self::SEM) {
                return $r;
            }
        }
        foreach ($res as $r) {
            if (count($r['list']) > 0) {
                return $r;
            }
        }
        return $res[0] ?? null;
    }

    /** Detalhe do plano de um banco (linhas por data + totais por período), paginado (50). @return array<string,mixed> */
    public function planDetail(string $bank, int $period, bool $showRec, int $page): array
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        $sel = $this->selectBank($proj['res'], $bank);
        if ($sel === null) {
            return ['bank' => null, 'rows' => [], 'page' => 0, 'pages' => 1, 'total' => 0];
        }
        $rows = [];
        foreach ($proj['periods'] as $i => $per) {
            if ($period !== -1 && $period !== $i) {
                continue;
            }
            $has = false;
            foreach ($sel['list'] as $e) {
                if ($e['p'] !== $i) {
                    continue;
                }
                $has = true;
                if ($e['item']['tipo'] === 'R' && !$showRec) {
                    continue;
                }
                $short = $e['saldo'] + $sel['lim'] < 0;
                $it = $e['item'];
                $origem = '';
                if ($it['tipo'] === 'P' && !isset($e['sug'])) {
                    $origem = ($it['banco'] ?? '') !== '' ? 'documento' : ((($c['partyBank'][Engine::pkOf($it)] ?? '') === $sel['id']) ? 'fornecedor' : 'defeito');
                }
                $rows[] = [
                    'k' => 'ev', 'date' => Util::isoOf($e['n']), 'semanaIso' => Util::isoWeekLabelN($e['n']), 'atraso' => $e['atraso'], 'alterada' => ($it['dataPlan'] ?? '') !== '',
                    'tipo' => $it['tipo'], 'tdoc' => $it['tdoc'], 'doc' => $it['doc'], 'parte' => $it['parte'], 'rec' => Engine::isRecIt($it),
                    'valor' => $e['v'], 'saldo' => $e['saldo'],
                    'estado' => $it['tipo'] === 'P' ? ($short ? 'falta' : ($e['saldo'] < 0 ? 'ccc' : '')) : '',
                    'sug' => isset($e['sug']) ? ['ok' => $e['sug']['ok'], 'head' => $e['sug']['head']] : null, 'origemBanco' => $origem,
                ];
            }
            if (!$has) {
                continue;
            }
            $cl = $sel['close'][$i];
            $rows[] = [
                'k' => 'tot', 'label' => $per['label'], 'pag' => $sel['pag'][$i], 'rec' => $sel['rec'][$i], 'saldo' => $cl,
                'estado' => $cl + $sel['lim'] < 0 ? 'neg' : ($cl < 0 ? 'ccc' : 'ok'),
            ];
        }
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PP));
        $pg = max(0, min($page, $pages - 1));
        return [
            'bank' => ['id' => $sel['id'], 'nome' => $sel['nome'], 'saldo' => $sel['saldo'], 'ccc' => $sel['ccc'], 'lim' => $sel['lim']],
            'rows' => array_slice($rows, $pg * self::PP, self::PP),
            'page' => $pg, 'pages' => $pages, 'total' => $total,
        ];
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        $s = $c['settings'];
        $inclV = Engine::inclV($proj, $s);
        $d = Engine::dash($proj, $inclV);
        $periods = $proj['periods'];
        $real = array_values(array_filter($proj['res'], fn ($r) => $r['id'] !== self::SEM));
        $short = array_values(array_filter($proj['res'], fn ($r) => $r['need'] > 0 && $r['id'] !== self::SEM));
        $sem = null;
        foreach ($proj['res'] as $r) {
            if ($r['id'] === self::SEM) {
                $sem = $r;
            }
        }
        $avg = static fn (array $x): float => $x['tot'] > 0 ? $x['wDays'] / $x['tot'] : 0.0;
        $age = static fn (array $x): float => $x['ov'] > 0 ? $x['ovAgeW'] / $x['ov'] : 0.0;
        $top = static function (array $list, float $tot): array {
            $o = [];
            foreach (array_slice($list, 0, 10) as $i => $e) {
                $o[] = ['n' => $i + 1, 'nome' => $e['nome'], 'v' => $e['v'], 'pct' => Util::pct($e['v'], $tot), 'ov' => $e['ov']];
            }
            return $o;
        };
        $side = $d['side'];
        $sideOut = [];
        foreach (['R', 'P'] as $t) {
            $x = $side[$t];
            $sideOut[$t] = $x + ['avgDias' => $avg($x), 'avgVencido' => $age($x), 'pctOv' => Util::pct($x['ov'], $x['tot'])];
        }
        return [
            'start' => $proj['startIso'], 'nPeriods' => count($periods), 'perLabel' => $proj['weekly'] ? 'semanas' : 'dias',
            'periods' => array_map(fn ($p) => ['label' => $p['label'], 'short' => $p['short'], 'iso' => $p['iso']], $periods),
            'saldo0' => $d['saldo0'], 'nBanks' => count($c['banks']), 'ccc' => $d['ccc'], 'useCCC' => (bool) $s['useCCC'],
            'recH' => array_sum($d['dRec']), 'pagH' => array_sum($d['dPag']),
            'saldoFinal' => $d['dClose'] !== [] ? $d['dClose'][count($d['dClose']) - 1] : $d['saldo0'],
            'minV' => $d['minV'], 'minLabel' => $d['minI'] < 0 ? 'no início' : $periods[$d['minI']]['label'], 'minI' => $d['minI'],
            'shortBanks' => array_map(fn ($r) => [
                'nome' => $r['nome'], 'saldo' => $r['saldo'], 'min' => $r['min'], 'first' => $r['first'] !== null ? Util::isoOf($r['first']) : null,
                'periodos' => count(array_filter($r['close'], fn ($x) => $x + $r['lim'] < 0)), 'need' => $r['need'],
            ], $short),
            'nReal' => count($real),
            'sug' => ['n' => $proj['sugN'], 'v' => $proj['sugV'], 'badN' => $proj['sugBadN'], 'badV' => $proj['sugBadV']],
            'sem' => ['pagT' => $sem['pagT'] ?? 0.0, 'recT' => $sem['recT'] ?? 0.0],
            'side' => $sideOut, 'bands' => Engine::BANDS,
            'dRec' => $d['dRec'], 'dPag' => $d['dPag'], 'dClose' => $d['dClose'],
            'banks' => array_values(array_map(fn ($r) => ['nome' => $r['nome'], 'saldo' => $r['saldo'], 'ccc' => $r['ccc'], 'final' => $r['final']],
                array_filter($proj['res'], fn ($r) => $r['saldo'] != 0.0 || count($r['list']) > 0))),
            'topR' => $top($d['topR'], $side['R']['tot']), 'topP' => $top($d['topP'], $side['P']['tot']),
            'topRSum' => array_sum(array_column(array_slice($d['topR'], 0, 10), 'v')), 'topPSum' => array_sum(array_column(array_slice($d['topP'], 0, 10), 'v')),
            'topRPct' => Util::pct((float) array_sum(array_column(array_slice($d['topR'], 0, 10), 'v')), $side['R']['tot']),
            'topPPct' => Util::pct((float) array_sum(array_column(array_slice($d['topP'], 0, 10), 'v')), $side['P']['tot']),
        ];
    }

    /** @return array<string,mixed> */
    public function suggestions(): array
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        $inclV = Engine::inclV($proj, $c['settings']);
        $d = Engine::dash($proj, $inclV);
        return ['items' => Engine::suggestions($proj, $d, $inclV, $c['settings'])];
    }

    /** @return array<string,mixed> */
    public function openItems(string $tipo, string $banco, string $prazo, string $q, int $page): array
    {
        if (!in_array($tipo, ['R', 'P'], true)) {
            throw new HttpException(400, 'Parâmetro tipo inválido (R ou P).');
        }
        $c = $this->context();
        $proj = $this->projFor($c);
        $startN = $proj['startN'];
        $bankOf = $this->bankOfFn($c);
        $eff = static function (array $it) use ($bankOf, $proj): string {
            $b = $bankOf($it);
            return $b !== '' ? $b : (string) ($proj['sugMap'][$it['id']]['bank'] ?? '');
        };
        $ql = Util::lower($q);
        $pool = $prazo === 'excl'
            ? array_values(array_filter($c['excluded'], fn ($x) => $x['tipo'] === $tipo))
            : array_values(array_filter($proj['view'], fn ($x) => $x['tipo'] === $tipo));
        $list = [];
        foreach ($pool as $it) {
            $b = $prazo === 'excl' ? $bankOf($it) : $eff($it);
            if ($banco !== '' && $banco !== ($b !== '' ? $b : self::SEM)) {
                continue;
            }
            if ($prazo !== '' && $prazo !== 'excl') {
                $d = Engine::plannedOf($it);
                $ov = $d !== null && Util::dayNum($d) < $startN;
                if ($prazo === 'venc' && !$ov) {
                    continue;
                }
                if ($prazo === 'avenc' && $ov) {
                    continue;
                }
                if ($prazo === 'cred' && $it['valor'] > 0) {
                    continue;
                }
            }
            if ($ql !== '' && !str_contains(Util::lower($it['doc'] . ' ' . $it['parte'] . ' ' . $it['tdoc'] . ' ' . $it['origem']), $ql)) {
                continue;
            }
            $list[] = $it;
        }
        usort($list, static function (array $a, array $b): int {
            $da = ($a['dataPlan'] ?? '') !== '' ? (Util::parseDate($a['dataPlan']) ?? $a['venc']) : $a['venc'];
            $db = ($b['dataPlan'] ?? '') !== '' ? (Util::parseDate($b['dataPlan']) ?? $b['venc']) : $b['venc'];
            return ($da <=> $db) ?: ($b['valor'] <=> $a['valor']);
        });
        $total = count($list);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $pg = max(0, min($page, $pages - 1));
        $out = [];
        foreach (array_slice($list, $pg * self::PAGE, self::PAGE) as $it) {
            $b0 = $bankOf($it);
            $sg = $proj['sugMap'][$it['id']] ?? null;
            $b = $b0 !== '' ? $b0 : (string) ($sg['bank'] ?? '');
            $d = Engine::plannedOf($it);
            $src = '';
            if ($b !== '') {
                $src = $b0 === '' ? ($sg['ok'] ? 'sugerido' : 'sugerido-sem-fundos')
                    : (($it['banco'] ?? '') === $b ? 'documento' : ((($c['partyBank'][Engine::pkOf($it)] ?? '') === $b) ? 'entidade' : 'defeito'));
            }
            $net = $proj['eff'][$it['id']] ?? null;
            $out[] = [
                'id' => $it['id'], 'tipo' => $it['tipo'], 'tdoc' => $it['tdoc'], 'doc' => $it['doc'], 'parte' => $it['parte'],
                'party' => $it['party'], 'venc' => $it['venc'], 'semanaIso' => Util::isoWeekLabel($it['venc']),
                'dataPlan' => $it['dataPlan'] ?? '', 'semanaIsoPlan' => Util::isoWeekLabel($it['dataPlan'] ?? ''), 'valor' => $it['valor'],
                'liquido' => ($net !== null && abs($net - $it['valor']) > 0.004) ? $net : null,
                'banco' => (string) ($it['banco'] ?? ''), 'bancoEfetivo' => $b, 'bancoOrigem' => $src,
                'flag' => (string) ($it['flag'] ?? ''), 'origem' => $it['origem'], 'nota' => self::notaOf($it), 'editable' => $it['editable'],
                'recorrente' => Engine::isRecIt($it),
                'excluded' => $it['excluded'] ?? false, 'overridden' => $it['overridden'] ?? false,
                'prazoDias' => $d !== null ? Util::dayNum($d) - $startN : null,
            ];
        }
        $all = array_values(array_filter($proj['view'], fn ($x) => $x['tipo'] === $tipo));
        $sem = array_values(array_filter($all, fn ($x) => $eff($x) === ''));
        $sum = fn (array $a): float => (float) array_sum(array_map(fn ($x) => $x['valor'], $a));
        return [
            'items' => $out, 'page' => $pg, 'pages' => $pages, 'total' => $total,
            'summary' => ['count' => count($all), 'liquido' => $sum($all), 'semCount' => count($sem), 'semValor' => $sum($sem), 'excluidos' => count(array_filter($c['excluded'], fn ($x) => $x['tipo'] === $tipo))],
        ];
    }

    /** Entidades (clientes e fornecedores) da vista de trabalho. @param array<string,mixed> $proj @return list<array<string,mixed>> */
    private function entities(array $proj): array
    {
        $startN = $proj['startN'];
        $m = [];
        foreach ($proj['view'] as $it) {
            if (($it['party'] ?? '') === '') {
                continue;
            }
            $k = $it['party'];
            if (!isset($m[$k])) {
                $m[$k] = ['key' => $k, 'nome' => $it['parte'], 'n' => 0, 'net' => 0.0, 'ov' => 0.0, 'side' => $it['tipo']];
            }
            $m[$k]['n']++;
            $m[$k]['net'] += $it['valor'];
            $d = Engine::plannedOf($it);
            if ($d !== null && Util::dayNum($d) < $startN && $it['valor'] > 0) {
                $m[$k]['ov'] += $it['valor'];
            }
        }
        $arr = array_values($m);
        usort($arr, static fn ($a, $b) => (abs($b['net']) + $b['ov']) <=> (abs($a['net']) + $a['ov']));
        return $arr;
    }

    /** @return list<array<string,mixed>> entidades filtradas */
    private function filteredEntities(array $c, string $side, string $q, bool $only): array
    {
        $proj = $this->projFor($c);
        $bankIds = array_fill_keys(array_column($c['banks'], 'id'), true);
        $ql = Util::lower($q);
        $out = [];
        foreach ($this->entities($proj) as $e) {
            if ($e['side'] !== $side) {
                continue;
            }
            $pb = (string) ($c['partyBank'][$e['key']] ?? '');
            $has = $pb !== '' && isset($bankIds[$pb]);
            if ($only && $has) {
                continue;
            }
            if ($ql !== '' && !str_contains(Util::lower($e['nome'] . ' ' . $e['key']), $ql)) {
                continue;
            }
            $e['banco'] = $has ? $pb : '';
            $out[] = $e;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function entitiesPage(string $side, string $q, bool $only, int $page): array
    {
        if (!in_array($side, ['R', 'P'], true)) {
            throw new HttpException(400, 'Parâmetro side inválido (R ou P).');
        }
        $c = $this->context();
        $proj = $this->projFor($c);
        $list = $this->filteredEntities($c, $side, $q, $only);
        $total = count($list);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $pg = max(0, min($page, $pages - 1));
        $all = $this->entities($proj);
        $bankIds = array_fill_keys(array_column($c['banks'], 'id'), true);
        $has = fn ($e): bool => isset($bankIds[(string) ($c['partyBank'][$e['key']] ?? '')]);
        $cliSem = array_values(array_filter($all, fn ($e) => $e['side'] === 'R' && !$has($e)));
        return [
            'items' => array_slice($list, $pg * self::PAGE, self::PAGE),
            'page' => $pg, 'pages' => $pages, 'total' => $total,
            'assigned' => count(array_filter($all, $has)), 'entities' => count($all),
            'toApplyEmpty' => count(array_filter($list, fn ($e) => $e['banco'] === '')),
            'cliSem' => ['count' => count($cliSem), 'net' => array_sum(array_column($cliSem, 'net')),
                'nomes' => array_map(fn ($e) => ['nome' => $e['nome'], 'codigo' => substr($e['key'], 1)], array_slice($cliSem, 0, 30))],
        ];
    }

    // ------------------------------------------------------------------ escrita

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function updateSettings(array $in): array
    {
        $c = $this->context();
        $bankIds = array_column($c['banks'], 'id');
        $errors = [];
        $new = [];
        foreach ($in as $k => $v) {
            switch ($k) {
                case 'inicio':
                    $t = is_string($v) ? trim($v) : '';
                    if ($t === '') {
                        $new[$k] = '';
                    } elseif (($d = Util::parseDate($t)) !== null) {
                        $new[$k] = $d;
                    } else {
                        $errors[$k] = 'Data de início inválida: use DD/MM/AAAA ou AAAA-MM-DD (vazio = data do Sage).';
                    }
                    break;
                case 'horiz':
                    if (in_array((string) $v, self::HORIZONS, true)) {
                        $new[$k] = (string) $v;
                    } else {
                        $errors[$k] = 'Horizonte inválido (' . implode(', ', self::HORIZONS) . ' semanas).';
                    }
                    break;
                case 'vista':
                    if (in_array($v, ['Semanal', 'Diária'], true)) {
                        $new[$k] = $v;
                    } else {
                        $errors[$k] = 'Vista inválida (Semanal ou Diária).';
                    }
                    break;
                case 'useCCC':
                case 'netting':
                case 'incPend':
                case 'incOut':
                case 'incRec':
                    if (is_bool($v)) {
                        $new[$k] = $v;
                    } else {
                        $errors[$k] = 'Deve ser verdadeiro ou falso.';
                    }
                    break;
                case 'ruleR':
                case 'ruleP':
                    if ($v === 'x' || (is_numeric($v) && (string) (int) $v === (string) $v && (int) $v >= 0 && (int) $v <= 365)) {
                        $new[$k] = (string) $v;
                    } else {
                        $errors[$k] = 'Use "x" (fora do plano) ou um número de dias entre 0 e 365.';
                    }
                    break;
                case 'defBankR':
                case 'defBankP':
                    if ($v === '' || $v === null || in_array($v, $bankIds, true)) {
                        $new[$k] = (string) $v;
                    } else {
                        $errors[$k] = 'Banco desconhecido.';
                    }
                    break;
                case 'modoP':
                    if (in_array($v, Engine::MODOS, true)) {
                        $new[$k] = $v;
                    } else {
                        $errors[$k] = 'Modo inválido (' . implode(', ', Engine::MODOS) . ').';
                    }
                    break;
                case 'crit':
                    if (in_array($v, Engine::CRITS, true)) {
                        $new[$k] = $v;
                    } else {
                        $errors[$k] = 'Critério inválido (' . implode(', ', Engine::CRITS) . ').';
                    }
                    break;
                default:
                    $errors[(string) $k] = 'Definição desconhecida.';
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Dados inválidos.', $errors);
        }
        $demo = $c['demo'];
        $this->store->mutateState($demo, function (array $st) use ($new): array {
            $st['settings'] = array_merge($st['settings'], $new);
            return [$st, null];
        });
        $this->invalidate();
        return $this->context()['settings'];
    }

    /** Ordem de prioridade dos bancos (critério "Ordem de prioridade"). @param list<mixed> $order @return list<string> */
    public function setPriority(array $order): array
    {
        $c = $this->context();
        $ids = array_column($c['banks'], 'id');
        $o = [];
        foreach ($order as $id) {
            if (!is_string($id) || !in_array($id, $ids, true)) {
                throw new HttpException(422, 'Dados inválidos.', ['order' => 'Banco desconhecido.']);
            }
            if (!in_array($id, $o, true)) {
                $o[] = $id;
            }
        }
        $this->store->mutateState($c['demo'], function (array $st) use ($o): array {
            $st['prio'] = $o;
            return [$st, null];
        });
        $this->invalidate();
        return $this->projFor($this->context())['prioIds'];
    }

    /** @param array<string,mixed> $in @return array{0:string,1:float,2:float} */
    private function bankFields(array $in, ?array $base): array
    {
        $errors = [];
        $nome = $base['nome'] ?? 'Novo banco';
        if (array_key_exists('nome', $in)) {
            $nome = is_string($in['nome']) ? trim($in['nome']) : '';
            if ($nome === '' || mb_strlen($nome) > 100) {
                $errors['nome'] = 'Obrigatório, até 100 caracteres.';
            }
        }
        $vals = ['saldo' => (float) ($base['saldo'] ?? 0), 'ccc' => (float) ($base['ccc'] ?? 0)];
        foreach (['saldo', 'ccc'] as $k) {
            if (array_key_exists($k, $in)) {
                if ($in[$k] === null || (is_string($in[$k]) && trim($in[$k]) === '')) {
                    $vals[$k] = 0.0;
                } else {
                    $n = Util::parseNum($in[$k]);
                    if ($n === null || abs($n) > 1e12) {
                        $errors[$k] = 'Valor inválido.';
                    } else {
                        $vals[$k] = round($n, 2);
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Dados inválidos.', $errors);
        }
        return [(string) $nome, $vals['saldo'], $vals['ccc']];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function createBank(array $in): array
    {
        [$nome, $saldo, $ccc] = $this->bankFields($in, null);
        $demo = $this->context()['demo'];
        $bank = ['id' => 'b' . bin2hex(random_bytes(4)), 'nome' => $nome, 'saldo' => $saldo, 'ccc' => $ccc];
        $this->store->mutateState($demo, function (array $st) use ($bank): array {
            if (count($st['banks']) >= 40) {
                throw new HttpException(422, 'Limite de bancos atingido (40).');
            }
            $st['banks'][] = $bank;
            return [$st, null];
        });
        $this->invalidate();
        return $bank;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function updateBank(string $id, array $in): array
    {
        $demo = $this->context()['demo'];
        $out = $this->store->mutateState($demo, function (array $st) use ($id, $in): array {
            foreach ($st['banks'] as $i => $b) {
                if ((string) $b['id'] === $id) {
                    [$nome, $saldo, $ccc] = $this->bankFields($in, $b);
                    $st['banks'][$i] = ['id' => $id, 'nome' => $nome, 'saldo' => $saldo, 'ccc' => $ccc];
                    return [$st, $st['banks'][$i]];
                }
            }
            throw new HttpException(404, 'Banco não encontrado.');
        });
        $this->invalidate();
        return $out;
    }

    public function deleteBank(string $id): void
    {
        $demo = $this->context()['demo'];
        $this->store->mutateState($demo, function (array $st) use ($id): array {
            $before = count($st['banks']);
            $st['banks'] = array_values(array_filter($st['banks'], fn ($b) => (string) $b['id'] !== $id));
            if (count($st['banks']) === $before) {
                throw new HttpException(404, 'Banco não encontrado.');
            }
            $st = $this->dropBankRefs($st, [$id]);
            return [$st, null];
        });
        $this->invalidate();
    }

    /** @param array<string,mixed> $st @param list<string> $gone @return array<string,mixed> */
    private function dropBankRefs(array $st, array $gone): array
    {
        $g = array_fill_keys($gone, true);
        foreach ($st['manual'] as $i => $m) {
            if (isset($g[(string) ($m['banco'] ?? '')])) {
                $st['manual'][$i]['banco'] = '';
            }
        }
        foreach ($st['recs'] as $i => $r) {
            if (isset($g[(string) ($r['banco'] ?? '')])) {
                $st['recs'][$i]['banco'] = '';
            }
        }
        foreach ($st['overrides'] as $k => $o) {
            if (is_array($o) && isset($g[(string) ($o['banco'] ?? '')]) && array_key_exists('banco', $o)) {
                $st['overrides'][$k]['banco'] = '';
            }
        }
        $st['partyBank'] = array_filter($st['partyBank'], fn ($v) => !isset($g[(string) $v]));
        $st['prio'] = array_values(array_filter($st['prio'], fn ($v) => !isset($g[(string) $v])));
        foreach (['defBankR', 'defBankP'] as $k) {
            if (isset($g[(string) $st['settings'][$k]])) {
                $st['settings'][$k] = '';
            }
        }
        return $st;
    }

    /** @param array<string,mixed> $in @param array<string,mixed>|null $base @param list<string> $bankIds @return array<string,mixed> */
    private function entryFields(array $in, ?array $base, array $bankIds): array
    {
        $errors = [];
        $e = $base ?? ['tipo' => '', 'tdoc' => 'Man', 'doc' => '', 'parte' => '', 'party' => '', 'venc' => '', 'valor' => 0.0, 'banco' => '', 'dataPlan' => '', 'origem' => 'Manual', 'flag' => ''];
        $str = function (string $k, int $max) use ($in, &$e, &$errors): void {
            if (array_key_exists($k, $in)) {
                if (!is_string($in[$k]) && $in[$k] !== null) {
                    $errors[$k] = 'Deve ser texto.';
                } elseif (mb_strlen(trim((string) $in[$k])) > $max) {
                    $errors[$k] = 'Máximo ' . $max . ' caracteres.';
                } else {
                    $e[$k] = trim((string) $in[$k]);
                }
            }
        };
        if (array_key_exists('tipo', $in)) {
            if (in_array($in['tipo'], ['R', 'P'], true)) {
                $e['tipo'] = $in['tipo'];
            } else {
                $errors['tipo'] = 'Use R (recebimento) ou P (pagamento).';
            }
        }
        if (!in_array($e['tipo'], ['R', 'P'], true) && !isset($errors['tipo'])) {
            $errors['tipo'] = 'Obrigatório (R ou P).';
        }
        $str('tdoc', 10);
        $str('doc', 60);
        $str('parte', 120);
        $str('origem', 60);
        $str('party', 30);
        if ($e['tdoc'] === '') {
            $e['tdoc'] = 'Man';
        }
        if ($e['origem'] === '') {
            $e['origem'] = 'Manual';
        }
        if ($e['party'] !== '' && preg_match('/^[CF][A-Za-z0-9._\-]{1,29}$/', $e['party']) !== 1) {
            $errors['party'] = 'Código inválido (C ou F seguido do código Sage).';
        }
        if (array_key_exists('flag', $in)) {
            $e['flag'] = ($in['flag'] === 'p' || $in['flag'] === 'pendente') ? 'p' : '';
        }
        if (array_key_exists('venc', $in) || $e['venc'] === '') {
            $d = Util::parseDate((string) ($in['venc'] ?? ''));
            if ($d === null) {
                $errors['venc'] = 'Data de vencimento inválida: use DD/MM/AAAA ou AAAA-MM-DD.';
            } else {
                $e['venc'] = $d;
            }
        }
        if (array_key_exists('valor', $in) || (float) $e['valor'] === 0.0) {
            $v = Util::parseNum($in['valor'] ?? '');
            if ($v === null || $v == 0.0 || abs($v) > 1e12) {
                $errors['valor'] = 'Valor inválido: indique um montante, por exemplo 1234,56 (negativo para notas de crédito).';
            } else {
                $e['valor'] = round($v, 2);
            }
        }
        if (array_key_exists('dataPlan', $in)) {
            $t = trim((string) ($in['dataPlan'] ?? ''));
            if ($t === '') {
                $e['dataPlan'] = '';
            } elseif (($d = Util::parseDate($t)) !== null) {
                $e['dataPlan'] = $d;
            } else {
                $errors['dataPlan'] = 'Data planeada inválida: deixe em branco para usar o vencimento.';
            }
        }
        if (array_key_exists('banco', $in)) {
            $b = (string) ($in['banco'] ?? '');
            if ($b === self::SEM) {
                $b = '';
            }
            if ($b !== '' && !in_array($b, $bankIds, true)) {
                $errors['banco'] = 'Banco desconhecido.';
            } else {
                $e['banco'] = $b;
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Dados inválidos.', array_map('strval', $errors));
        }
        return $e;
    }

    /** @return list<array<string,mixed>> */
    public function manualEntries(): array
    {
        return $this->context()['state']['manual'];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function createManual(array $in): array
    {
        $c = $this->context();
        $e = $this->entryFields($in, null, array_column($c['banks'], 'id'));
        $e['id'] = 'm' . bin2hex(random_bytes(5));
        $this->store->mutateState($c['demo'], function (array $st) use ($e): array {
            if (count($st['manual']) >= 5000) {
                throw new HttpException(422, 'Limite de entradas manuais atingido.');
            }
            $st['manual'][] = $e;
            return [$st, null];
        });
        $this->invalidate();
        return $e;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function updateManual(string $id, array $in): array
    {
        $c = $this->context();
        $ids = array_column($c['banks'], 'id');
        $out = $this->store->mutateState($c['demo'], function (array $st) use ($id, $in, $ids): array {
            foreach ($st['manual'] as $i => $m) {
                if ($m['id'] === $id) {
                    $e = $this->entryFields($in, $m, $ids);
                    $e['id'] = $id;
                    $st['manual'][$i] = $e;
                    return [$st, $e];
                }
            }
            throw new HttpException(404, 'Entrada não encontrada.');
        });
        $this->invalidate();
        return $out;
    }

    public function deleteManual(string $id): void
    {
        $c = $this->context();
        $this->store->mutateState($c['demo'], function (array $st) use ($id): array {
            $n = count($st['manual']);
            $st['manual'] = array_values(array_filter($st['manual'], fn ($m) => $m['id'] !== $id));
            if (count($st['manual']) === $n) {
                throw new HttpException(404, 'Entrada não encontrada.');
            }
            return [$st, null];
        });
        $this->invalidate();
    }

    /** Alterações a documentos do Sage (banco, data planeada, vencimento, valor, excluir). @param array<string,mixed> $in @return array<string,mixed> */
    public function setOverride(string $id, array $in): array
    {
        $c = $this->context();
        $known = false;
        foreach (array_merge($c['items'], $c['excluded']) as $it) {
            if ($it['id'] === $id && $it['editable'] === 'sage') {
                $known = true;
                break;
            }
        }
        if (!$known) {
            throw new HttpException(404, 'Documento Sage não encontrado.');
        }
        $errors = [];
        $o = [];
        $bankIds = array_column($c['banks'], 'id');
        foreach ($in as $k => $v) {
            switch ($k) {
                case 'banco':
                    $b = $v === self::SEM ? '' : (string) $v;
                    if ($b !== '' && !in_array($b, $bankIds, true)) {
                        $errors[$k] = 'Banco desconhecido.';
                    } else {
                        $o[$k] = $b;
                    }
                    break;
                case 'dataPlan':
                    $t = trim((string) $v);
                    if ($t === '') {
                        $o[$k] = '';
                    } elseif (($d = Util::parseDate($t)) !== null) {
                        $o[$k] = $d;
                    } else {
                        $errors[$k] = 'Data planeada inválida.';
                    }
                    break;
                case 'venc':
                    $d = Util::parseDate((string) $v);
                    if ($d === null) {
                        $errors[$k] = 'Data de vencimento inválida.';
                    } else {
                        $o[$k] = $d;
                    }
                    break;
                case 'valor':
                    $n = Util::parseNum($v);
                    if ($n === null || $n == 0.0 || abs($n) > 1e12) {
                        $errors[$k] = 'Valor inválido.';
                    } else {
                        $o[$k] = round($n, 2);
                    }
                    break;
                case 'excluded':
                    if (is_bool($v)) {
                        $o[$k] = $v;
                    } else {
                        $errors[$k] = 'Deve ser verdadeiro ou falso.';
                    }
                    break;
                default:
                    $errors[(string) $k] = 'Campo desconhecido.';
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Dados inválidos.', $errors);
        }
        $this->store->mutateState($c['demo'], function (array $st) use ($id, $o): array {
            $cur = (array) ($st['overrides'][$id] ?? []);
            $cur = array_merge($cur, $o);
            if (empty($cur['excluded'])) {
                unset($cur['excluded']);
            }
            if ($cur === []) {
                unset($st['overrides'][$id]);
            } else {
                $st['overrides'][$id] = $cur;
            }
            return [$st, null];
        });
        $this->invalidate();
        return ['id' => $id, 'override' => $this->context()['state']['overrides'][$id] ?? null];
    }

    public function clearOverride(?string $id): int
    {
        $c = $this->context();
        $n = (int) $this->store->mutateState($c['demo'], function (array $st) use ($id): array {
            $n = $id === null ? count($st['overrides']) : (isset($st['overrides'][$id]) ? 1 : 0);
            if ($id === null) {
                $st['overrides'] = [];
            } else {
                unset($st['overrides'][$id]);
            }
            return [$st, $n];
        });
        $this->invalidate();
        return $n;
    }


    /** @param string $banco '' remove atribuição */
    public function setPartyBank(string $key, string $banco): void
    {
        if (preg_match('/^[CF][A-Za-z0-9._\-]{1,29}$/', $key) !== 1) {
            throw new HttpException(400, 'Código de entidade inválido.');
        }
        $c = $this->context();
        if ($banco !== '' && !in_array($banco, array_column($c['banks'], 'id'), true)) {
            throw new HttpException(422, 'Dados inválidos.', ['banco' => 'Banco desconhecido.']);
        }
        $this->store->mutateState($c['demo'], function (array $st) use ($key, $banco): array {
            if ($banco === '') {
                unset($st['partyBank'][$key]);
            } else {
                $st['partyBank'][$key] = $banco;
            }
            return [$st, null];
        });
        $this->invalidate();
    }

    /** Atribuição em massa às entidades do filtro. mode: empty | all | clear. @return array<string,int> */
    public function bulkPartyBank(string $side, string $q, bool $only, string $mode, string $banco): array
    {
        if (!in_array($mode, ['empty', 'all', 'clear'], true)) {
            throw new HttpException(400, 'Modo inválido (empty, all ou clear).');
        }
        $c = $this->context();
        if ($mode !== 'clear' && !in_array($banco, array_column($c['banks'], 'id'), true)) {
            throw new HttpException(422, 'Dados inválidos.', ['banco' => 'Escolha um banco.']);
        }
        $list = $this->filteredEntities($c, $side, $q, $only);
        $keys = [];
        foreach ($list as $e) {
            if ($mode === 'empty' && $e['banco'] !== '') {
                continue;
            }
            $keys[] = $e['key'];
        }
        $this->store->mutateState($c['demo'], function (array $st) use ($keys, $mode, $banco): array {
            foreach ($keys as $k) {
                if ($mode === 'clear') {
                    unset($st['partyBank'][$k]);
                } else {
                    $st['partyBank'][$k] = $banco;
                }
            }
            return [$st, null];
        });
        $this->invalidate();
        return ['changed' => count($keys)];
    }

    // ------------------------------------------------------------------ entidades nos dois lados

    /** Tratamento de uma entidade nos dois lados: 'F' (fornecedor), 'C' (cliente) ou '' (classificação automática). */
    public function setDualClass(string $terc, string $classe): void
    {
        if (preg_match('/^[A-Za-z0-9._\-]{1,29}$/', $terc) !== 1) {
            throw new HttpException(400, 'Código de entidade inválido.');
        }
        if (!in_array($classe, ['F', 'C', ''], true)) {
            throw new HttpException(422, 'Dados inválidos.', ['classe' => 'Use F (fornecedor), C (cliente) ou vazio (automático).']);
        }
        $c = $this->context();
        $known = false;
        foreach ($this->projFor($c)['dual'] as $e) {
            if ($e['terc'] === $terc) {
                $known = true;
            }
        }
        if (!$known) {
            throw new HttpException(404, 'Entidade não encontrada entre as que estão nos dois lados.');
        }
        $this->store->mutateState($c['demo'], function (array $st) use ($terc, $classe): array {
            if ($classe === '') {
                unset($st['classOv'][$terc]);
            } else {
                $st['classOv'][$terc] = $classe;
            }
            return [$st, null];
        });
        $this->invalidate();
    }

    public function resetDualClasses(): void
    {
        $c = $this->context();
        $this->store->mutateState($c['demo'], function (array $st): array {
            $st['classOv'] = [];
            return [$st, null];
        });
        $this->invalidate();
    }

    // ------------------------------------------------------------------ movimentos recorrentes

    /** @param array<string,mixed> $in @param array<string,mixed>|null $base @param list<string> $bankIds @return array<string,mixed> */
    private function recFields(array $in, ?array $base, array $bankIds): array
    {
        $errors = [];
        $r = $base ?? ['desc' => '', 'cat' => Engine::CATS[0], 'tipo' => 'P', 'valor' => 0.0, 'banco' => '', 'freq' => 'Mensal', 'dia' => 1, 'ini' => '', 'fim' => '', 'fds' => Engine::FDS[0], 'ativo' => true];
        if (array_key_exists('desc', $in)) {
            $t = is_string($in['desc']) ? trim($in['desc']) : '';
            if ($t === '' || mb_strlen($t) > 120) {
                $errors['desc'] = 'Indique uma descrição (até 120 caracteres).';
            } else {
                $r['desc'] = $t;
            }
        } elseif ($r['desc'] === '') {
            $errors['desc'] = 'Indique uma descrição.';
        }
        if (array_key_exists('cat', $in)) {
            if (in_array($in['cat'], Engine::CATS, true)) {
                $r['cat'] = $in['cat'];
            } else {
                $errors['cat'] = 'Categoria inválida (' . implode(', ', Engine::CATS) . ').';
            }
        }
        if (array_key_exists('tipo', $in)) {
            if (in_array($in['tipo'], ['R', 'P'], true)) {
                $r['tipo'] = $in['tipo'];
            } else {
                $errors['tipo'] = 'Use R (recebimento) ou P (pagamento).';
            }
        }
        if (array_key_exists('valor', $in) || (float) $r['valor'] <= 0) {
            $v = Util::parseNum($in['valor'] ?? '');
            if ($v === null || $v <= 0 || $v > 1e12) {
                $errors['valor'] = 'Valor inválido: indique um montante positivo, por exemplo 1500,00. O sinal vem do tipo (pagamento ou recebimento).';
            } else {
                $r['valor'] = round($v, 2);
            }
        }
        if (array_key_exists('banco', $in)) {
            $b = (string) ($in['banco'] ?? '');
            if ($b === self::SEM) {
                $b = '';
            }
            if ($b !== '' && !in_array($b, $bankIds, true)) {
                $errors['banco'] = 'Banco desconhecido.';
            } else {
                $r['banco'] = $b;
            }
        }
        if (array_key_exists('freq', $in)) {
            if (in_array($in['freq'], Engine::FREQS, true)) {
                $r['freq'] = $in['freq'];
            } else {
                $errors['freq'] = 'Frequência inválida (' . implode(', ', Engine::FREQS) . ').';
            }
        }
        if (array_key_exists('dia', $in) || array_key_exists('freq', $in)) {
            $raw = $in['dia'] ?? $r['dia'];
            $d = is_numeric($raw) ? (int) $raw : -1;
            if (!is_numeric($raw) && is_string($raw)) { // nome do dia da semana
                $k = mb_substr(Util::lower($raw), 0, 3);
                foreach (Engine::WDN as $i => $w) {
                    if (mb_substr(Util::lower($w), 0, 3) === $k && mb_strlen($k) >= 3) {
                        $d = $i;
                    }
                }
            }
            if ($r['freq'] === 'Semanal' ? ($d < 0 || $d > 6) : ($d < 1 || $d > 31)) {
                $errors['dia'] = $r['freq'] === 'Semanal' ? 'Dia da semana inválido (0 = segunda … 6 = domingo).' : 'Dia do mês inválido (1 a 31).';
            } else {
                $r['dia'] = $d;
            }
        }
        if (array_key_exists('ini', $in) || $r['ini'] === '') {
            $d = Util::parseDate((string) ($in['ini'] ?? ''));
            if ($d === null) {
                $errors['ini'] = 'Data de início inválida: use DD/MM/AAAA ou AAAA-MM-DD.';
            } else {
                $r['ini'] = $d;
            }
        }
        if (array_key_exists('fim', $in)) {
            $t = trim((string) ($in['fim'] ?? ''));
            if ($t === '') {
                $r['fim'] = '';
            } elseif (($d = Util::parseDate($t)) !== null) {
                $r['fim'] = $d;
            } else {
                $errors['fim'] = 'Data de fim inválida: deixe em branco se não houver fim.';
            }
        }
        if (!isset($errors['ini']) && !isset($errors['fim']) && $r['fim'] !== '' && $r['fim'] < $r['ini']) {
            $errors['fim'] = 'A data de fim é anterior à data de início.';
        }
        if (array_key_exists('fds', $in)) {
            if (in_array($in['fds'], Engine::FDS, true)) {
                $r['fds'] = $in['fds'];
            } else {
                $errors['fds'] = 'Regra de fim de semana inválida (' . implode(', ', Engine::FDS) . ').';
            }
        }
        if (array_key_exists('ativo', $in)) {
            if (is_bool($in['ativo'])) {
                $r['ativo'] = $in['ativo'];
            } else {
                $errors['ativo'] = 'Deve ser verdadeiro ou falso.';
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Dados inválidos.', $errors);
        }
        return $r;
    }

    /** @return array<string,mixed> */
    public function recurring(): array
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        [$a, $b] = Engine::recRange($c['settings'], $c['ref']);
        $ids = array_fill_keys(array_column($c['banks'], 'id'), true);
        $rows = [];
        foreach ($c['state']['recs'] as $r) {
            $occ = Engine::expandRec($r, $a, $b);
            $rows[] = $r + [
                'bancoValido' => ($r['banco'] ?? '') !== '' && isset($ids[$r['banco']]),
                'ocorrencias' => count($occ), 'datas' => array_map(fn ($n) => Util::dm($n), array_slice($occ, 0, 4)), 'maisDatas' => count($occ) > 4,
            ];
        }
        $rp = array_filter($proj['recItems'], fn ($x) => $x['tipo'] === 'P');
        $rr = array_filter($proj['recItems'], fn ($x) => $x['tipo'] === 'R');
        return [
            'items' => $rows, 'incRec' => (bool) $c['settings']['incRec'], 'start' => $proj['startIso'],
            'ativos' => count(array_filter($c['state']['recs'], fn ($x) => !empty($x['ativo']))),
            'nP' => count($rp), 'vP' => array_sum(array_column($rp, 'valor')), 'nR' => count($rr), 'vR' => array_sum(array_column($rr, 'valor')),
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function createRecurring(array $in): array
    {
        $c = $this->context();
        $r = $this->recFields($in, null, array_column($c['banks'], 'id'));
        $r['id'] = 'r' . bin2hex(random_bytes(5));
        $this->store->mutateState($c['demo'], function (array $st) use ($r): array {
            if (count($st['recs']) >= 500) {
                throw new HttpException(422, 'Limite de movimentos recorrentes atingido.');
            }
            $st['recs'][] = $r;
            return [$st, null];
        });
        $this->invalidate();
        return $r;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function updateRecurring(string $id, array $in): array
    {
        $c = $this->context();
        $ids = array_column($c['banks'], 'id');
        $out = $this->store->mutateState($c['demo'], function (array $st) use ($id, $in, $ids): array {
            foreach ($st['recs'] as $i => $r) {
                if ($r['id'] === $id) {
                    $n = $this->recFields($in, $r, $ids);
                    $n['id'] = $id;
                    $st['recs'][$i] = $n;
                    return [$st, $n];
                }
            }
            throw new HttpException(404, 'Movimento recorrente não encontrado.');
        });
        $this->invalidate();
        return $out;
    }

    public function deleteRecurring(string $id): void
    {
        $c = $this->context();
        $this->store->mutateState($c['demo'], function (array $st) use ($id): array {
            $n = count($st['recs']);
            $st['recs'] = array_values(array_filter($st['recs'], fn ($r) => $r['id'] !== $id));
            if (count($st['recs']) === $n) {
                throw new HttpException(404, 'Movimento recorrente não encontrado.');
            }
            return [$st, null];
        });
        $this->invalidate();
    }

    // ------------------------------------------------------------------ banco sugerido: aceitar

    /** Converte a sugestão em atribuições manuais. mode: 'fornecedor' (por entidade) | 'documento'. @return array<string,mixed> */
    public function acceptSuggestion(string $mode): array
    {
        if (!in_array($mode, ['fornecedor', 'documento'], true)) {
            throw new HttpException(400, 'Modo inválido (fornecedor ou documento).');
        }
        $c = $this->context();
        $proj = $this->projFor($c);
        $m = $proj['sugMap'];
        if ($m === []) {
            return ['message' => 'Não há pagamentos com banco sugerido.', 'changed' => 0];
        }
        if ($mode === 'fornecedor') {
            $tot = [];
            foreach ($proj['view'] as $it) {
                $s = $m[$it['id']] ?? null;
                if ($s === null) {
                    continue;
                }
                $k = Engine::pkOf($it);
                $tot[$k][$s['bank']] = ($tot[$k][$s['bank']] ?? 0.0) + abs((float) $it['valor']);
            }
            $this->store->mutateState($c['demo'], function (array $st) use ($tot): array {
                foreach ($tot as $k => $t) {
                    $bb = '';
                    $bv = -1.0;
                    foreach ($t as $b => $v) {
                        if ($v > $bv) {
                            $bv = $v;
                            $bb = (string) $b;
                        }
                    }
                    if ($bb !== '') {
                        $st['partyBank'][(string) $k] = $bb;
                    }
                }
                return [$st, null];
            });
            $this->invalidate();
            return ['changed' => count($tot), 'message' => 'Sugestão aceite por fornecedor: ' . count($tot) . ' fornecedores ficaram com o banco que recebe a maior parte dos seus pagamentos (em Banco por entidade). Passam a ser atribuições manuais e prevalecem sobre a sugestão. Atenção: um banco por fornecedor perde a precisão por documento e pode mostrar mais falta de fundos do que a sugestão por documento.'];
        }
        $docBank = [];
        foreach ($c['items'] as $x) {
            $s = $m[$x['id']] ?? null;
            if ($s !== null && $x['tipo'] === 'P') {
                $docBank[$x['id']] = [$x['editable'], $s['bank']];
            }
        }
        $recBank = [];
        foreach ($proj['recItems'] as $it) {
            $s = $m[$it['id']] ?? null;
            $rid = explode('|', $it['id'])[1] ?? '';
            if ($s !== null && !isset($recBank[$rid])) {
                $recBank[$rid] = $s['bank'];
            }
        }
        $this->store->mutateState($c['demo'], function (array $st) use ($docBank, $recBank): array {
            foreach ($st['manual'] as $i => $x) {
                if (isset($docBank[$x['id']])) {
                    $st['manual'][$i]['banco'] = $docBank[$x['id']][1];
                }
            }
            foreach ($docBank as $id => [$kind, $bank]) {
                if ($kind === 'sage') {
                    $st['overrides'][$id] = array_merge((array) ($st['overrides'][$id] ?? []), ['banco' => $bank]);
                }
            }
            foreach ($st['recs'] as $i => $r) {
                if (isset($recBank[$r['id']]) && ($r['banco'] ?? '') === '') {
                    $st['recs'][$i]['banco'] = $recBank[$r['id']];
                }
            }
            return [$st, null];
        });
        $this->invalidate();
        return ['changed' => count($docBank), 'message' => 'Sugestão aceite por documento: os pagamentos com banco sugerido ficaram com esse banco como atribuição manual (movimentos recorrentes: o banco da primeira ocorrência). As contas de cliente compensadas de fornecedores continuam sugeridas.'];
    }

    // ------------------------------------------------------------------ relação cliente → banco

    /** @return array<string,mixed> */
    public function uploadClientes(string $tmpPath, string $origName): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new HttpException(422, 'Formato não suportado: envie o ficheiro clientes_bancos.xlsx (BPCNUM_0, BPCNAM_0, PAYBAN_0, DES_0).');
        }
        $data = $this->store->replaceClientes($tmpPath, $ext);
        $this->invalidate();
        return $this->reloadClientes($data) + ['rows' => $data['rows'], 'ignored' => $data['ignored'], 'warnings' => $data['warnings']];
    }

    /** "Repor bancos dos clientes (ficheiro)": volta a carregar a relação do ficheiro, substituindo as atribuições de clientes. @return array<string,mixed> */
    public function resetClientes(): array
    {
        $c = $this->context();
        $this->invalidate();
        return $this->reloadClientes($c['cli']);
    }

    /** @param array<string,mixed> $cli @return array<string,mixed> */
    private function reloadClientes(array $cli): array
    {
        $demo = $this->store->isDemo();
        $cli = $this->store->loadClientes($demo);
        $sig = self::cliSig($cli);
        $n = 0;
        $created = 0;
        $this->store->mutateState($demo, function (array $st) use ($cli, $sig, &$n, &$created): array {
            [$st, $n, $created] = $this->seedClientes($st, $cli);
            $st['cliSig'] = $sig;
            return [$st, null];
        });
        $this->invalidate();
        return ['clientes' => $n, 'bancosCriados' => $created, 'message' => $n . ' clientes com banco carregados do ficheiro' . ($created > 0 ? '; ' . $created . ' bancos novos criados com saldo 0' : '') . '.'];
    }

    /** Repõe as alterações feitas na página: documentos Sage alterados, classificação dos dois lados, recorrentes, prioridade e bancos dos clientes. @return array<string,mixed> */
    public function reset(): array
    {
        $c = $this->context();
        $cli = $this->store->loadClientes($c['demo']);
        $sig = self::cliSig($cli);
        $this->store->mutateState($c['demo'], function (array $st) use ($cli, $sig): array {
            $st['overrides'] = [];
            $st['classOv'] = [];
            $st['recs'] = [];
            $st['prio'] = [];
            $def = $this->store->defaultState()['settings'];
            $st['settings'] = array_merge($st['settings'], $def);
            $st['partyBank'] = [];
            [$st] = $this->seedClientes($st, $cli);
            $st['cliSig'] = $sig;
            return [$st, null];
        });
        $this->invalidate();
        return ['message' => 'Alterações repostas: documentos do Sage, tratamento das entidades dos dois lados, movimentos recorrentes, prioridade, parâmetros e bancos dos clientes (ficheiro). Os saldos dos bancos e as entradas manuais mantêm-se.'];
    }

    // ------------------------------------------------------------------ importar / exportar (CSV colado)

    public function export(string $kind): string
    {
        $c = $this->context();
        $proj = $this->projFor($c);
        $bankIds = array_fill_keys(array_column($c['banks'], 'id'), true);
        $names = array_column($c['banks'], 'nome', 'id');
        $bn = fn (string $b): string => ($b !== '' && isset($bankIds[$b])) ? (string) $names[$b] : '';
        if ($kind === 'documents') {
            $lines = ['tipo;tipo_doc;documento;codigo;entidade;vencimento;valor;banco;data_planeada;origem;estado;semana_iso_venc;semana_iso_planeada'];
            foreach ($c['items'] as $it) {
                if ((float) $it['valor'] == 0.0) {
                    continue;
                }
                $lines[] = implode(';', array_map([Util::class, 'csvQ'], [
                    $it['tipo'] === 'R' ? 'Recebimento' : 'Pagamento', $it['tdoc'], $it['doc'], $it['party'], $it['parte'], Util::fmtD($it['venc']),
                    Util::fmtIn((float) $it['valor']), $bn((string) ($it['banco'] ?? '')),
                    ($it['dataPlan'] ?? '') !== '' ? Util::fmtD($it['dataPlan']) : '', $it['origem'], str_contains((string) $it['flag'], 'p') ? 'pendente' : '',
                    Util::isoWeekLabel($it['venc']), Util::isoWeekLabel($it['dataPlan'] ?? ''),
                ]));
            }
            return implode("\n", $lines);
        }
        if ($kind === 'recurring') {
            $lines = ['descricao;categoria;tipo;valor;banco;frequencia;dia;inicio;fim;fim_de_semana;ativo'];
            foreach ($c['state']['recs'] as $r) {
                $lines[] = implode(';', array_map([Util::class, 'csvQ'], [
                    $r['desc'], $r['cat'], $r['tipo'] === 'R' ? 'Recebimento' : 'Pagamento', Util::fmtIn((float) $r['valor']), $bn((string) ($r['banco'] ?? '')),
                    $r['freq'], $r['freq'] === 'Semanal' ? (Engine::WDN[(int) $r['dia']] ?? (string) $r['dia']) : (string) $r['dia'],
                    Util::fmtD($r['ini']), $r['fim'] !== '' ? Util::fmtD($r['fim']) : '', $r['fds'], !empty($r['ativo']) ? 'sim' : 'não',
                ]));
            }
            return implode("\n", $lines);
        }
        if ($kind === 'balances') {
            $lines = ['banco;saldo;ccc_disponivel'];
            foreach ($c['banks'] as $b) {
                $lines[] = implode(';', array_map([Util::class, 'csvQ'], [$b['nome'], Util::fmtIn($b['saldo']), Util::fmtIn($b['ccc'])]));
            }
            return implode("\n", $lines);
        }
        if ($kind === 'plan') {
            $lines = ['banco;banco_origem;documento;fornecedor;vencimento;semana_iso_venc;data_prevista;semana_iso_prevista;valor;saldo_apos;nota'];
            foreach ($proj['res'] as $r) {
                foreach ($r['list'] as $e) {
                    $it = $e['item'];
                    if ($it['tipo'] !== 'P') {
                        continue;
                    }
                    $origem = isset($e['sug']) ? ($e['sug']['ok'] ? 'sugerido' : 'sugerido (precisa de transferência ou CCC)')
                        : (($it['banco'] ?? '') !== '' ? 'documento' : ((($c['partyBank'][Engine::pkOf($it)] ?? '') === $r['id']) ? 'fornecedor' : ($r['id'] === self::SEM ? 'sem banco' : 'por defeito')));
                    $lines[] = implode(';', array_map([Util::class, 'csvQ'], [
                        $r['nome'], $origem, $it['doc'] !== '' ? $it['doc'] : $it['tdoc'], $it['parte'], Util::fmtD($it['venc']), Util::isoWeekLabel($it['venc']),
                        Util::fmtD(Util::isoOf($e['n'])), Util::isoWeekLabelN($e['n']), Util::fmtIn($e['v']), Util::fmtIn($e['saldo']), self::notaOf($it),
                    ]));
                }
            }
            return implode("\n", $lines);
        }
        if ($kind === 'entities') {
            $lines = ['codigo;entidade;banco'];
            foreach ($this->entities($proj) as $e) {
                $lines[] = implode(';', array_map([Util::class, 'csvQ'], [$e['key'], $e['nome'], $bn((string) ($c['partyBank'][$e['key']] ?? ''))]));
            }
            return implode("\n", $lines);
        }
        throw new HttpException(400, 'Tipo de exportação inválido (documents, recurring, balances, entities ou plan).');
    }

    /** @return array{message:string} */
    public function import(string $kind, string $text, bool $replace): array
    {
        if (strlen($text) > 5_000_000) {
            throw new HttpException(413, 'Texto demasiado grande.');
        }
        $lines = array_values(array_filter(preg_split('/\r?\n/', $text) ?: [], fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            throw new HttpException(422, 'Cole primeiro os dados no campo de importação.');
        }
        $delim = Util::detectDelim($lines[0]);
        $c = $this->context();
        $errors = [];
        $msg = '';
        $this->store->mutateState($c['demo'], function (array $st) use ($kind, $lines, $delim, $replace, &$errors, &$msg): array {
            $banks = $st['banks'];
            $nBefore = count($banks);
            $resolve = function (string $name, bool $create) use (&$banks): string {
                $t = trim($name);
                if ($t === '' || strtolower($t) === 'sem banco') {
                    return '';
                }
                foreach ($banks as $b) {
                    if (Util::lower($b['nome']) === Util::lower($t)) {
                        return (string) $b['id'];
                    }
                }
                if (!$create) {
                    return '';
                }
                $nb = ['id' => 'b' . bin2hex(random_bytes(4)), 'nome' => mb_substr($t, 0, 100), 'saldo' => 0.0, 'ccc' => 0.0];
                $banks[] = $nb;
                return $nb['id'];
            };
            if ($kind === 'balances') {
                $next = $replace ? [] : $banks;
                $n = 0;
                foreach ($lines as $li => $l) {
                    $cells = Util::splitLine($l, $delim);
                    if ($li === 0 && str_starts_with(Util::lower($cells[0]), 'banco')) {
                        continue;
                    }
                    $nome = $cells[0] ?? '';
                    $sv = Util::parseNum($cells[1] ?? '');
                    $cc = Util::parseNum($cells[2] ?? '') ?? 0.0;
                    if ($nome === '' || $sv === null) {
                        $errors[] = 'linha ' . ($li + 1);
                        continue;
                    }
                    $found = null;
                    foreach ($next as $i => $b) {
                        if (Util::lower($b['nome']) === Util::lower($nome)) {
                            $found = $i;
                            break;
                        }
                    }
                    $old = null;
                    foreach ($st['banks'] as $b) {
                        if (Util::lower($b['nome']) === Util::lower($nome)) {
                            $old = $b;
                            break;
                        }
                    }
                    if ($found !== null) {
                        $next[$found]['saldo'] = round($sv, 2);
                        $next[$found]['ccc'] = round($cc, 2);
                    } else {
                        $next[] = ['id' => $old['id'] ?? 'b' . bin2hex(random_bytes(4)), 'nome' => mb_substr($nome, 0, 100), 'saldo' => round($sv, 2), 'ccc' => round($cc, 2)];
                    }
                    $n++;
                }
                $st['banks'] = array_values($next);
                $gone = array_values(array_diff(array_column($banks, 'id'), array_column($next, 'id')));
                $st = $this->dropBankRefs($st, $gone);
                $msg = $n . ' bancos importados' . ($errors ? '; ignoradas: ' . implode(', ', array_slice($errors, 0, 12)) : '') . '.';
                return [$st, null];
            }
            if ($kind === 'entities') {
                $nm = $replace ? [] : $st['partyBank'];
                $n = 0;
                foreach ($lines as $li => $l) {
                    $cells = Util::splitLine($l, $delim);
                    if ($li === 0 && str_starts_with(Util::lower($cells[0]), 'codigo')) {
                        continue;
                    }
                    if (($cells[0] ?? '') === '' || preg_match('/^[CF][A-Za-z0-9._\-]{1,29}$/', $cells[0]) !== 1) {
                        $errors[] = 'linha ' . ($li + 1);
                        continue;
                    }
                    $b = $resolve($cells[2] ?? '', true);
                    if ($b !== '') {
                        $nm[$cells[0]] = $b;
                    } else {
                        unset($nm[$cells[0]]);
                    }
                    $n++;
                }
                $st['banks'] = $banks;
                $st['partyBank'] = $nm;
                $created = count($banks) - $nBefore;
                $msg = $n . ' entidades atualizadas' . ($created > 0 ? '; ' . $created . ' bancos novos criados com saldo 0' : '') . ($errors ? '; ignoradas: ' . implode(', ', array_slice($errors, 0, 12)) : '') . '.';
                return [$st, null];
            }
            if ($kind === 'recurring') {
                $novas = [];
                foreach ($lines as $li => $l) {
                    $cells = Util::splitLine($l, $delim);
                    if ($li === 0 && str_starts_with(Util::lower($cells[0]), 'descri')) {
                        continue;
                    }
                    $t = Util::lower($cells[2] ?? '');
                    $tipo = str_starts_with($t, 'r') ? 'R' : (str_starts_with($t, 'p') ? 'P' : null);
                    $v = Util::parseNum($cells[3] ?? '');
                    $fq = null;
                    foreach (Engine::FREQS as $f) {
                        if (Util::lower($f) === Util::lower($cells[5] ?? '')) {
                            $fq = $f;
                        }
                    }
                    $ini = Util::parseDate($cells[7] ?? '');
                    $fimRaw = trim($cells[8] ?? '');
                    $fim = $fimRaw === '' ? '' : Util::parseDate($fimRaw);
                    $dia = null;
                    if ($fq === 'Semanal') {
                        $k = Util::lower($cells[6] ?? '');
                        foreach (Engine::WDN as $wi => $w) {
                            if (mb_strlen($k) >= 3 && mb_substr(Util::lower($w), 0, 3) === mb_substr($k, 0, 3)) {
                                $dia = $wi;
                            }
                        }
                        if ($dia === null && preg_match('/^[1-7]$/', $k) === 1) {
                            $dia = (int) $k - 1;
                        }
                    } else {
                        $dn = trim($cells[6] ?? '');
                        $dia = (preg_match('/^\d{1,2}$/', $dn) === 1 && (int) $dn >= 1 && (int) $dn <= 31) ? (int) $dn : null;
                    }
                    if (trim($cells[0] ?? '') === '' || $tipo === null || $v === null || $v <= 0 || $fq === null || $ini === null || $fim === null || $dia === null || ($fim !== '' && $fim < $ini)) {
                        $errors[] = 'linha ' . ($li + 1);
                        continue;
                    }
                    $ct = Util::lower($cells[1] ?? '');
                    $cat = null;
                    foreach (Engine::CATS as $x) {
                        if (Util::lower($x) === $ct) {
                            $cat = $x;
                        }
                    }
                    $cat ??= str_starts_with($ct, 'imp') ? Engine::CATS[2] : (str_starts_with($ct, 'sal') ? Engine::CATS[1] : (str_starts_with($ct, 'seg') ? Engine::CATS[3] : (str_starts_with($ct, 'ren') ? Engine::CATS[0] : Engine::CATS[4])));
                    $f = Util::lower($cells[9] ?? '');
                    $fds = str_starts_with($f, 'adiant') ? Engine::FDS[1] : (str_starts_with($f, 'adiar') ? Engine::FDS[2] : Engine::FDS[0]);
                    $at = Util::lower($cells[10] ?? '');
                    $novas[] = [
                        'id' => 'r' . bin2hex(random_bytes(5)), 'desc' => mb_substr(trim($cells[0]), 0, 120), 'cat' => $cat, 'tipo' => $tipo, 'valor' => round($v, 2),
                        'banco' => $resolve($cells[4] ?? '', true), 'freq' => $fq, 'dia' => $dia, 'ini' => $ini, 'fim' => $fim, 'fds' => $fds,
                        'ativo' => !in_array($at, ['não', 'nao', '0', 'false', 'n'], true),
                    ];
                }
                $st['banks'] = $banks;
                $st['recs'] = array_slice($replace ? $novas : array_merge($st['recs'], $novas), 0, 500);
                $created = count($banks) - $nBefore;
                $msg = count($novas) . ' movimentos recorrentes importados' . ($replace ? ' (lista substituída)' : '')
                    . ($created > 0 ? '; ' . $created . ' bancos novos criados com saldo 0' : '')
                    . ($errors ? '; linhas ignoradas: ' . implode(', ', array_slice($errors, 0, 12)) : '') . '.';
                return [$st, null];
            }
            if ($kind === 'documents') {
                $novos = [];
                foreach ($lines as $li => $l) {
                    $cells = Util::splitLine($l, $delim);
                    if ($li === 0 && str_starts_with(Util::lower($cells[0]), 'tipo')) {
                        continue;
                    }
                    $t = Util::lower($cells[0] ?? '');
                    $tipo = str_starts_with($t, 'r') ? 'R' : (str_starts_with($t, 'p') ? 'P' : null);
                    $venc = Util::parseDate($cells[5] ?? '');
                    $v = Util::parseNum($cells[6] ?? '');
                    $dpRaw = $cells[8] ?? '';
                    $dp = $dpRaw !== '' ? Util::parseDate($dpRaw) : '';
                    $party = $cells[3] ?? '';
                    if ($tipo === null || $venc === null || $v === null || $v == 0.0 || $dp === null
                        || ($party !== '' && preg_match('/^[CF][A-Za-z0-9._\-]{1,29}$/', $party) !== 1)) {
                        $errors[] = (string) ($li + 1);
                        continue;
                    }
                    $novos[] = [
                        'id' => 'm' . bin2hex(random_bytes(5)), 'tipo' => $tipo, 'tdoc' => mb_substr($cells[1] ?? '', 0, 10) ?: 'Imp',
                        'doc' => mb_substr($cells[2] ?? '', 0, 60), 'party' => $party, 'parte' => mb_substr($cells[4] ?? '', 0, 120),
                        'venc' => $venc, 'valor' => round($v, 2), 'banco' => $resolve($cells[7] ?? '', true), 'dataPlan' => $dp,
                        'origem' => mb_substr($cells[9] ?? '', 0, 60) ?: 'Importado', 'flag' => str_starts_with(Util::lower($cells[10] ?? ''), 'pend') ? 'p' : '',
                    ];
                }
                $st['banks'] = $banks;
                $st['manual'] = $replace ? $novos : array_merge($st['manual'], $novos);
                $created = count($banks) - $nBefore;
                $msg = count($novos) . ' entradas manuais importadas' . ($replace ? ' (entradas manuais anteriores substituídas)' : '')
                    . ($created > 0 ? '; ' . $created . ' bancos novos criados com saldo 0' : '')
                    . ($errors ? '; linhas ignoradas: ' . implode(', ', array_slice($errors, 0, 12)) . (count($errors) > 12 ? '…' : '') : '') . '.';
                return [$st, null];
            }
            throw new HttpException(400, 'Tipo de importação inválido (documents, recurring, balances ou entities).');
        });
        $this->invalidate();
        return ['message' => $msg];
    }

    /** @return array<string,mixed> */
    public function uploadSage(string $tmpPath, string $origName): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            throw new HttpException(422, 'Formato não suportado: envie um ficheiro .xls ou .xlsx do Sage X3.');
        }
        $data = $this->store->replaceSage($tmpPath, $ext);
        $this->invalidate();
        return ['ref' => $data['ref'], 'rows' => $data['rows'], 'warnings' => $data['warnings'], 'importedAt' => $data['importedAt']];
    }
}
