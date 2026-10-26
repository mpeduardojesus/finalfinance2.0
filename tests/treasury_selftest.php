<?php
declare(strict_types=1);

/**
 * Auto-teste da lógica do Plano de tesouraria com os DADOS FICTÍCIOS de data/sample (sem dados reais).
 * Uso: php tests/treasury_selftest.php
 */
require __DIR__ . '/../src/autoload.php';

use App\Treasury\ClientesImporter;
use App\Treasury\Engine;
use App\Treasury\Util;

$fail = 0;
$check = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? '[ok]   ' : '[FALHA] ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

// Util
$check(Util::parseNum('1.234,56') === 1234.56, 'parseNum 1.234,56');
$check(Util::parseNum('1,234.56') === 1234.56, 'parseNum 1,234.56');
$check(Util::parseNum('(12,50)') === -12.5, 'parseNum (12,50)');
$check(Util::parseNum('1.234') === 1234.0, 'parseNum 1.234 (milhares)');
$check(Util::parseNum('abc') === null, 'parseNum inválido');
$check(Util::parseDate('26/10/2026') === '2026-10-26', 'parseDate DD/MM/AAAA');
$check(Util::parseDate('31/02/2026') === null, 'parseDate inválida');
$check(Util::isoWeek(Util::dayNum('2026-10-26')) === 44, 'semana ISO 44 a 26/10/2026');
$check(Util::isoOf(Util::mondayOf(Util::dayNum('2026-10-28'))) === '2026-10-26', 'segunda-feira da semana');

// ---------------------------------------------------------------- motor (Engine::compute)
const REF = '2026-10-26';
$S0 = ['inicio' => '', 'horiz' => '4', 'vista' => 'Semanal', 'useCCC' => false, 'netting' => true, 'incPend' => true, 'incOut' => true, 'incRec' => true,
    'ruleR' => '0', 'ruleP' => '0', 'defBankR' => '', 'defBankP' => '', 'modoP' => 'Manual', 'crit' => 'Maior folga'];
$comp = fn (array $items, array $banks, array $s = [], array $extra = []) => Engine::compute(['items' => $items, 'banks' => $banks, 's' => $s + $S0, 'ref' => REF] + $extra);
$mk = fn (string $id, string $tipo, string $venc, float $v, string $banco = 'b1', string $party = '') => [
    'id' => $id, 'tipo' => $tipo, 'tdoc' => 'T', 'doc' => $id, 'parte' => 'X', 'party' => $party !== '' ? $party : ($tipo === 'R' ? 'C9' : 'F1'),
    'venc' => $venc, 'valor' => $v, 'banco' => $banco, 'dataPlan' => '', 'origem' => 'Sage X3', 'flag' => '',
];
$bank = fn (string $id, float $saldo = 0.0, float $ccc = 0.0) => ['id' => $id, 'nome' => strtoupper($id), 'saldo' => $saldo, 'ccc' => $ccc];
$banks = [$bank('b1', 10.0, 100.0)];
$byId = function (array $p, string $id): array {
    foreach ($p['res'] as $r) {
        if ($r['id'] === $id) {
            return $r;
        }
    }
    return ['pagT' => 0.0, 'recT' => 0.0, 'list' => [], 'final' => 0.0, 'need' => 0.0];
};

// Compensação de créditos + ordenação
$items = [$mk('a', 'P', '2026-10-30', 100.0), $mk('b', 'P', '2026-11-05', 50.0), $mk('c', 'P', '2026-10-20', -120.0), $mk('r', 'R', '2026-10-30', 30.0)];
$p = $comp($items, $banks);
$r = $byId($p, 'b1');
$check(abs($r['pagT'] - 30.0) < 0.001, 'compensação: pagamentos líquidos = 30 (obtido ' . $r['pagT'] . ')');
$check(abs($r['recT'] - 30.0) < 0.001, 'recebimentos = 30');
$check($p['compens'] === 2, 'duas linhas ficam a zero por compensação (' . $p['compens'] . ')');
$p2 = $comp($items, $banks, ['netting' => false]);
$check(abs($byId($p2, 'b1')['pagT'] - (100 + 50 - 120)) < 0.001, 'sem compensação, o crédito entra como linha própria (negativa)');

// Regras de vencidos
$p3 = $comp([$mk('v', 'P', '2026-10-01', 40.0)], $banks, ['ruleP' => 'x']);
$check($p3['vencFora'] === 1 && abs(abs($p3['vencForaV']) - 40.0) < 0.001, 'vencido fora do plano (regra x)');
$p4 = $comp([$mk('v', 'P', '2026-10-01', 40.0)], $banks, ['ruleP' => '7']);
$check($byId($p4, 'b1')['list'][0]['n'] === Util::dayNum('2026-11-02'), 'vencido +7 dias após o início');

// Prioridade do banco: documento > fornecedor/cliente > defeito > sem banco (modo Fixo/Manual)
$bk3 = [$bank('b1'), $bank('b2'), $bank('b3')];
$it = $mk('z', 'P', '2026-11-01', 1.0, '');
$where = function (array $p): string { foreach ($p['res'] as $r) { if (count($r['list']) > 0) { return $r['id']; } } return ''; };
$check($where($comp([$it], $bk3)) === '__sem', 'sem banco (modo Manual)');
$check($where($comp([$it], $bk3, ['modoP' => 'Fixo', 'defBankP' => 'b3'])) === 'b3', 'modo Fixo: banco de pagamento por defeito');
$check($where($comp([$it], $bk3, ['modoP' => 'Fixo', 'defBankP' => 'b3'], ['partyBank' => ['F1' => 'b2']])) === 'b2', 'banco do fornecedor > defeito');
$check($where($comp([['banco' => 'b1'] + $it], $bk3, ['modoP' => 'Fixo', 'defBankP' => 'b3'], ['partyBank' => ['F1' => 'b2']])) === 'b1', 'banco do documento > fornecedor');
$rc = $mk('rc', 'R', '2026-11-01', 5.0, '');
$check($where($comp([$rc], $bk3, ['defBankR' => 'b2'])) === 'b2', 'recebimento sem banco usa o banco de recebimento por defeito');
$check($where($comp([$rc], $bk3, ['defBankR' => 'b2'], ['partyBank' => ['C9' => 'b3']])) === 'b3', 'banco do cliente (ficheiro) > defeito de recebimento');
$check($where($comp([$rc], $bk3)) === '__sem', 'cliente sem banco fica em Sem banco atribuído');
$ps = $comp([$it, $rc], $bk3);
$semR = $byId($ps, '__sem');
$check(abs($semR['pagT'] - 1.0) < 0.001 && abs($semR['recT'] - 5.0) < 0.001, 'Sem banco atribuído acumula pagamentos e recebimentos');
$check($ps['res'][count($ps['res']) - 1]['id'] === '__sem' && $ps['res'][0]['id'] !== '__sem', 'Sem banco atribuído não conta como banco (último, só quando há movimentos)');

// Terceiro nos dois lados: fornecedor (lado a pagar maior) → a pagar pela diferença; cliente → duas entradas
$sup = [$mk('p1', 'P', '2026-11-02', 1000.0, 'b1', 'F7'), $mk('r1', 'R', '2026-11-03', 300.0, 'b1', 'C7')];
$pc = $comp($sup, $banks);
$check(abs($byId($pc, 'b1')['pagT'] - 700.0) < 0.001 && abs($byId($pc, 'b1')['recT']) < 0.001, 'fornecedor nos dois lados: a pagar 700 (1000 − 300), sem recebimento');
$check(count($pc['dual']) === 1 && count($pc['sup']) === 1, 'uma entidade dos dois lados, tratada como fornecedor');
$pn = $comp($sup, $banks, [], ['classOv' => ['7' => 'C']]);
$check(abs($byId($pn, 'b1')['pagT'] - 1000.0) < 0.001 && abs($byId($pn, 'b1')['recT'] - 300.0) < 0.001, 'classificação manual Cliente: 1000 a pagar e 300 a receber, sem compensação entre lados');
$cli = $sup;
$cli[0]['valor'] = 100.0;
$pcl = $comp($cli, $banks);
$check(abs($byId($pcl, 'b1')['pagT'] - 100.0) < 0.001 && abs($byId($pcl, 'b1')['recT'] - 300.0) < 0.001, 'cliente nos dois lados (receber maior): duas entradas separadas');
$check(count($pcl['sup']) === 0, 'sem fornecedores nos dois lados');

// Banco sugerido (modo Sugerido, determinístico)
$mkp = fn (string $id, string $venc, float $v) => $mk($id, 'P', $venc, $v, '');
$two = [$bank('b1', 100.0), $bank('b2', 1000.0)];
$sg = fn (array $items, array $banks, array $s = [], array $extra = []) => $comp($items, $banks, ['modoP' => 'Sugerido'] + $s, $extra);
$x = $sg([$mkp('p', '2026-10-30', 500.0)], $two);
$check($x['sugMap']['p']['bank'] === 'b2' && $x['sugMap']['p']['ok'] === true && abs($x['sugMap']['p']['head'] - 500.0) < 0.001, 'maior folga: banco com mais saldo (b2, folga 500)');
$x = $sg([$mkp('p', '2026-10-30', 500.0)], [$bank('b1', 600.0), $bank('b2', 1000.0)], ['crit' => 'Minimizar transferências']);
$check($x['sugMap']['p']['bank'] === 'b1', 'minimizar transferências: ajuste justo (b1, a menor folga que ainda cabe)');
$x = $sg([$mkp('p', '2026-10-30', 500.0)], [$bank('b1', 600.0), $bank('b2', 1000.0)], ['crit' => 'Ordem de prioridade'], ['prio' => ['b1', 'b2']]);
$check($x['sugMap']['p']['bank'] === 'b1', 'ordem de prioridade: primeiro banco que cabe');
$x = $sg([$mkp('p', '2026-10-30', 500.0)], [$bank('b1', 600.0), $bank('b2', 1000.0)], ['crit' => 'Ordem de prioridade'], ['prio' => ['b2', 'b1']]);
$check($x['sugMap']['p']['bank'] === 'b2', 'ordem de prioridade invertida: b2');
$x = $sg([$mkp('p', '2026-10-30', 500.0)], [$bank('b1', 300.0, 400.0), $bank('b2', 600.0)], ['useCCC' => true, 'crit' => 'Minimizar uso de CCC']);
$check($x['sugMap']['p']['bank'] === 'b2', 'minimizar uso de CCC: b2 cobre sem CCC');
$x = $sg([$mkp('p', '2026-10-30', 5000.0)], $two);
$check($x['sugMap']['p']['ok'] === false && $x['sugMap']['p']['bank'] === 'b2' && $x['sugBadN'] === 1, 'nenhum banco cobre: escolhe o com menos falta e assinala (sugBadN 1)');
$x = $sg([$mkp('p', '2026-10-30', 800.0), $mkp('q', '2026-10-30', 800.0)], $two);
$check($x['sugMap']['p']['bank'] !== $x['sugMap']['q']['bank'] || $x['sugMap']['q']['ok'] === false, 'o 2.º pagamento já conta o 1.º (não cabem os dois em b2 sem falta)');
// recebimento futuro do banco aumenta a folga; crédito de fornecedor conta como entrada antes dos pagamentos do mesmo dia
$x = $sg([$mkp('p', '2026-10-30', 900.0), $mk('c', 'P', '2026-10-30', -500.0, 'b1', 'F2')], [$bank('b1', 500.0)]);
$check($x['sugMap']['p']['ok'] === true, 'crédito de fornecedor no mesmo dia entra antes do pagamento (500 + 500 ≥ 900)');
$a1 = json_encode($sg([$mkp('p', '2026-10-30', 100.0), $mkp('q', '2026-10-30', 100.0), $mkp('r', '2026-10-30', 100.0)], [$bank('b1', 1000.0), $bank('b2', 1000.0)])['sugMap']);
$a2 = json_encode($sg([$mkp('r', '2026-10-30', 100.0), $mkp('q', '2026-10-30', 100.0), $mkp('p', '2026-10-30', 100.0)], [$bank('b1', 1000.0), $bank('b2', 1000.0)])['sugMap']);
$ka = json_decode($a1, true); $kb = json_decode($a2, true); ksort($ka); ksort($kb);
$check($ka == $kb, 'sugestão determinística: a ordem dos itens de entrada não altera o resultado (desempate pelo identificador)');
$x = $sg([$mkp('v', '2026-10-01', 100.0), $mkp('n', '2026-10-30', 900.0)], [$bank('b1', 950.0), $bank('b2', 100.0)]);
$check($x['sugMap']['v']['bank'] === 'b1' && $x['sugMap']['n']['ok'] === false, 'vencidos primeiro: o vencido apanha o banco com saldo, o outro já não cabe');
$x = $comp([$mkp('p', '2026-10-30', 100.0)], $two, ['modoP' => 'Manual']);
$check($x['sugN'] === 0 && count($x['sugMap']) === 0, 'modo Manual: sem sugestões');
$x = $sg([$mk('p', 'P', '2026-10-30', 100.0, 'b1')], $two);
$check($x['sugN'] === 0, 'pagamento com banco do documento não é sugerido');

// Recorrentes
$rec = fn (array $o) => $o + ['id' => 'x', 'desc' => 'Renda', 'cat' => 'Renda', 'tipo' => 'P', 'valor' => 2500.0, 'banco' => 'b1', 'freq' => 'Mensal', 'dia' => 1, 'ini' => '2026-11-01', 'fim' => '', 'fds' => 'Manter', 'ativo' => true];
$sH = ['horiz' => '10'];
$pr = $comp([], $banks, $sH, ['recs' => [$rec([])]]);
$check(count($pr['recItems']) === 3 && $pr['recItems'][0]['venc'] === '2026-11-01' && $pr['recItems'][2]['venc'] === '2027-01-01', 'mensal dia 1 desde 01/11: 01/11, 01/12 e 01/01 (3 em 10 semanas)');
$check(abs($byId($pr, 'b1')['pagT'] - 7500.0) < 0.001, 'recorrentes entram nos pagamentos do banco (7 500)');
$check(str_starts_with((string) $pr['recItems'][0]['origem'], 'Recorrente'), 'origem Recorrente');
$pa = $comp([], $banks, $sH, ['recs' => [$rec(['fds' => 'Adiar para dia útil'])]]);
$check($pa['recItems'][0]['venc'] === '2026-11-02', 'fim de semana adiar: domingo 01/11 → segunda 02/11');
$pf = $comp([], $banks, $sH, ['recs' => [$rec(['fds' => 'Adiantar para dia útil'])]]);
$check($pf['recItems'][0]['venc'] === '2026-10-30', 'fim de semana adiantar: domingo 01/11 → sexta 30/10');
$pw1 = $comp([], $banks, $sH, ['recs' => [$rec(['freq' => 'Semanal', 'dia' => 0, 'ini' => '2026-10-01'])]]);
$check(count($pw1['recItems']) === 10 && $pw1['recItems'][0]['venc'] === '2026-10-26', 'semanal à segunda: 10 ocorrências, a 1.ª em 26/10 (nunca antes do início do plano)');
$pq = $comp([], $banks, ['horiz' => '26'], ['recs' => [$rec(['freq' => 'Trimestral', 'ini' => '2026-11-30', 'dia' => 31])]]);
$check(count($pq['recItems']) >= 2 && $pq['recItems'][0]['venc'] === '2026-11-30' && $pq['recItems'][1]['venc'] === '2027-02-28', 'trimestral dia 31: último dia do mês (30/11, 28/02)');
$pe = $comp([], $banks, $sH, ['recs' => [$rec(['fim' => '2026-12-15'])]]);
$check(count($pe['recItems']) === 2, 'data de fim respeitada (01/11 e 01/12)');
$pd = $comp([], $banks, $sH, ['recs' => [$rec(['ativo' => false])]]);
$check(count($pd['recItems']) === 0, 'movimento desligado não gera ocorrências');
$pg = $comp([], $banks, ['incRec' => false] + $sH, ['recs' => [$rec([])]]);
$check(count($pg['recItems']) === 0 && abs($byId($pg, 'b1')['pagT']) < 0.001, 'interruptor global desliga todos os recorrentes');
$rr = $comp([], $banks, $sH, ['recs' => [$rec(['tipo' => 'R', 'valor' => 400.0, 'banco' => ''])]], );
$check(abs($byId($rr, '__sem')['recT'] - 1200.0) < 0.001, 'recebimento recorrente sem banco → Sem banco atribuído (sem defeito de recebimento)');
// recorrentes não entram em vencidos/aging/top
$base = $comp([$mk('o', 'P', '2026-10-01', 100.0), $mk('f', 'P', '2026-11-10', 50.0)], $banks, $sH);
$withR = $comp([$mk('o', 'P', '2026-10-01', 100.0), $mk('f', 'P', '2026-11-10', 50.0)], $banks, $sH, ['recs' => [$rec([])]]);
$d0 = Engine::dash($base, Engine::inclV($base, $S0)); $d1 = Engine::dash($withR, Engine::inclV($withR, $S0));
$check($d0['side']['P']['docs'] === $d1['side']['P']['docs'] && abs($d0['side']['P']['ov'] - $d1['side']['P']['ov']) < 0.001 && $d0['topP'] === $d1['topP'], 'recorrentes fora dos vencidos, aging, prazos médios e top 10');
$check(abs(array_sum($d1['dPag']) - array_sum($d0['dPag']) - 7500.0) < 0.001, '… mas entram nos pagamentos por período do dashboard');
$bk2 = [$bank('b1', 100000.0), $bank('b2', 100000.0)];
$sr = Engine::suggestions($withR, $d1, Engine::inclV($withR, $S0), $S0 + ['modoP' => 'Manual']);
$check(is_array($sr), 'sugestões calculam-se com recorrentes');
$prop = array_filter($sr, fn ($z) => str_contains($z['t'], 'Renda'));
$check(count($prop) === 0, 'recorrentes não são propostos para mudar de banco');

// Dashboard
$dd = Engine::dash($base, Engine::inclV($base, $S0));
$check($dd['side']['P']['ovDocs'] === 1 && abs($dd['side']['P']['ov'] - 100.0) < 0.001 && $dd['side']['P']['docs'] === 2, 'dashboard: 1 vencido (100) em 2 documentos a pagar');
$check(abs(array_sum($dd['side']['P']['aging']) - 100.0) < 0.001 && $dd['side']['P']['agingN'][0] + $dd['side']['P']['agingN'][1] + $dd['side']['P']['agingN'][2] + $dd['side']['P']['agingN'][3] + $dd['side']['P']['agingN'][4] === 1, 'dashboard: aging soma o vencido');
$check(abs($dd['saldo0'] - 10.0) < 0.001 && count($dd['dClose']) === 10, 'dashboard: saldo inicial e uma coluna por semana');

// Util: formatação pt-PT
$check(Util::eur(1234567.891) === "1\u{a0}234\u{a0}567,89 €" && Util::eur(1234.5) === "1234,50 €" && Util::eur(-12.5) === "-12,50 €", 'eur() pt-PT: separador de milhares só a partir de 5 dígitos, NBSP e vírgula');

// Relação cliente → banco (importador, só com o ficheiro FICTÍCIO de data/sample)
$ci = ClientesImporter::parse(__DIR__ . '/../data/sample/clientes-bancos-sample.xlsx');
$check($ci['map'] === ['9001' => 'Banco Exemplo A', '9002' => 'Banco Exemplo B', '9003' => 'Banco Exemplo B'] && $ci['ignored'] === 1, 'importador: códigos como texto, linha BPCNUM 0 ignorada');
$check(ClientesImporter::ALIAS[ClientesImporter::normBank('Millennium BCP')][0] === 'bcp' && ClientesImporter::ALIAS[ClientesImporter::normBank('Caixa Geral de Depósitos')][0] === 'cgd'
    && ClientesImporter::ALIAS[ClientesImporter::normBank('Banco Santander Totta')][0] === 'san' && ClientesImporter::ALIAS[ClientesImporter::normBank('Bank Millennium Polska PLN')][0] === 'mpp', 'importador: nomes de bancos (BCP, CGD, Santander, Polska) reconhecidos');
$check(ClientesImporter::normCode('00123') === '123' && ClientesImporter::normCode(456.0) === '456' && ClientesImporter::normCode('AB1') === 'AB1', 'importador: código sem zeros à esquerda');

// Semana ISO 8601 (AAAA-Www): casos de fronteira de ano
foreach ([
    '2026-12-31' => '2026-W53', '2027-01-01' => '2026-W53', '2027-01-03' => '2026-W53', '2027-01-04' => '2027-W01',
    '2025-12-29' => '2026-W01', '2026-01-01' => '2026-W01', '2024-12-30' => '2025-W01', '2021-01-03' => '2020-W53',
    '2020-12-31' => '2020-W53', '2026-10-26' => '2026-W44', '2026-11-01' => '2026-W44', '2026-11-02' => '2026-W45',
    '2024-02-29' => '2024-W09', '2015-12-31' => '2015-W53', '2016-01-03' => '2015-W53',
] as $d => $w) {
    $check(Util::isoWeekLabel($d) === $w, "semana ISO de $d = $w (obtido " . Util::isoWeekLabel($d) . ')');
}
$check(Util::isoWeekLabel('') === '' && Util::isoWeekLabel('xx') === '', 'semana ISO de data vazia/inválida = vazio');

// Agrupamento semanal por semana ISO a começar na semana ISO da data de início (fronteira de ano), totais coerentes
$mk2 = fn (string $id, string $tipo, string $venc, float $v) => [
    'id' => $id, 'tipo' => $tipo, 'tdoc' => 'T', 'doc' => $id, 'parte' => 'Y' . $id, 'party' => '', 'venc' => $venc, 'valor' => $v,
    'banco' => 'b1', 'dataPlan' => '', 'origem' => 'Sage X3', 'flag' => '',
];
$its = [$mk2('1', 'P', '2026-12-31', 100.0), $mk2('2', 'P', '2027-01-01', 10.0), $mk2('3', 'P', '2027-01-04', 20.0),
    $mk2('4', 'R', '2027-01-12', 500.0), $mk2('5', 'P', '2026-12-20', 7.0), $mk2('6', 'P', '2027-02-15', 9.0)];
$pw = $comp($its, [$bank('b1', 10.0, 100.0)], ['inicio' => '2026-12-30', 'horiz' => '4']);
$labs = array_column($pw['periods'], 'iso');
$check($labs === ['2026-W53', '2027-W01', '2027-W02', '2027-W03'], 'períodos: 2026-W53, 2027-W01..W03 (obtido ' . implode(',', $labs) . ')');
$check($pw['periods'][0]['start'] === '2026-12-28', 'a 1.ª semana começa na segunda-feira ISO (2026-12-28)');
$rb = $byId($pw, 'b1');
$check(abs($rb['pag'][0] - (100 + 10 + 7)) < 0.001, 'semana 2026-W53: 31/12, 01/01 e o vencido (7) = 117 (obtido ' . $rb['pag'][0] . ')');
$check(abs($rb['pag'][1] - 20) < 0.001 && abs($rb['rec'][2] - 500) < 0.001, '04/01 → 2027-W01 e 12/01 → 2027-W02');
$check($pw['fora'] === 1, '15/02 fora do horizonte de 4 semanas');
$check(abs(array_sum($rb['pag']) - $rb['pagT']) < 0.001 && abs(array_sum($rb['rec']) - $rb['recT']) < 0.001, 'soma das semanas = totais');
$check(abs(($rb['final'] - $rb['saldo']) - ($rb['recT'] - $rb['pagT'])) < 0.001 && abs(end($rb['close']) - $rb['final']) < 0.001, 'saldo final = saldo inicial + recebimentos − pagamentos');
// data planeada: o item cai na semana ISO da data planeada
$pl = $mk2('7', 'P', '2027-01-01', 50.0);
$pl['dataPlan'] = '2027-01-13';
$pp = $comp([$pl], [$bank('b1', 10.0, 100.0)], ['inicio' => '2026-12-30', 'horiz' => '4']);
$check(abs($byId($pp, 'b1')['pag'][2] - 50) < 0.001, 'data planeada 13/01 → 2027-W02');
// vista diária mantém-se (1 coluna por dia)
$pd = $comp($its, [$bank('b1', 10.0, 100.0)], ['inicio' => '2026-12-30', 'horiz' => '4', 'vista' => 'Diária']);
$check(count($pd['periods']) === 28 && $pd['periods'][0]['start'] === '2026-12-30', 'vista diária: 28 dias a partir da data de início');

echo $fail === 0 ? "\nTudo OK\n" : "\n$fail falhas\n";
exit($fail === 0 ? 0 : 1);
