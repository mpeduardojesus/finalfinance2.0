<?php
declare(strict_types=1);
// Gera o conjunto de demonstração FICTÍCIO (anonimizado): data/sample/sage-open-items-sample.xls + sage-items.json + treasury.json
// Uso: php tools/make_sample.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

mt_srand(2026);
$ref = '2026-10-26';
$refSerial = 46321;
$customers = [['9001', 'Cliente Alfa, S.A.', 'CGMN'], ['9002', 'Cliente Beta GmbH', 'CGUE'], ['9003', 'Cliente Gama, Lda.', 'CGPT'], ['9004', 'Cliente Delta SAS', 'CGUE']];
$suppliers = [['9101', 'Fornecedor Um, Lda.', 'FGMN'], ['9102', 'Fornecedor Dois, S.A.', 'FGMN'], ['9103', 'Fornecedor Tres GmbH', 'FGUE'], ['9104', 'Fornecedor Quatro, Lda.', 'FGPT'], ['9105', 'Fornecedor Cinco, S.L.', 'FGUE']];

$rows = [];
$seq = 1;
$mk = function (array $e, string $tipo, int $days, float $amt, string $status = 'Bom a Pagar', string $divisa = 'EUR') use (&$rows, &$seq, $refSerial): void {
    $doc = $tipo . 'E012609/' . str_pad((string) $seq++, 5, '0', STR_PAD_LEFT);
    $rows[] = [$e[0], $e[1], $tipo, $doc, 'EXT-' . $seq, $refSerial + $days - 30, $refSerial + $days, $e[2], -$days, $amt, $amt, 0, $status, $divisa];
};
foreach ($customers as $i => $c) {
    foreach ([-20, -3, 4, 11, 18, 25, 33, 47, 61] as $k => $d) {
        $mk($c, 'FCL', $d, round(900 + mt_rand(0, 900000) / 100 + $i * 400, 2));
    }
}
$mk($customers[0], 'NCC', -40, -1250.0);
$mk($customers[1], 'REC', -15, -3000.0);
$mk($customers[2], 'MOL', 9, 8200.0);
foreach ($suppliers as $i => $s) {
    foreach ([-9, 2, 7, 14, 21, 30, 44, 58] as $k => $d) {
        $mk($s, 'FFO', $d, -round(300 + mt_rand(0, 600000) / 100 + $i * 250, 2), ($i === 1 && $k === 3) ? 'Pendente Aprovação' : 'Bom a Pagar', ($i === 4 && $k === 7) ? 'USD' : 'EUR');
    }
}
$mk($suppliers[0], 'NCF', -30, 640.0);
$mk($suppliers[2], 'PAG', -12, 1500.0);

$ss = new Spreadsheet();
$ws = $ss->getActiveSheet();
// Layout igual ao do Sage X3: linha 1 com Sociedade/Data ref. e, a partir da coluna K, os cabeçalhos das colunas de dados.
$head = ['Socied. :', 'MCP', 'Empresa Exemplo, S.A.', 'Legislação :', 'POR', 'Portugal', 'Divisa :', 'EUR', 'Data ref. :', $refSerial,
    'Terc.', 'Nome', 'Tipo', 'Documento', 'Doc. Origem', 'Data Doc.', 'Data venc.', 'Colect.', 'Nº Dias', 'Valor', 'Valor a pagar', 'Valor Pago', 'Status', 'Divisa'];
foreach ($head as $c => $v) {
    $ws->setCellValue([$c + 1, 1], $v);
}
foreach ($rows as $r => $row) {
    foreach ($row as $c => $v) {
        $ws->setCellValue([$c + 1, $r + 2], $v);
    }
}
$dir = __DIR__ . '/../data/sample';
(new Xls($ss))->save($dir . '/sage-open-items-sample.xls');

$data = App\Treasury\SageImporter::parse($dir . '/sage-open-items-sample.xls');
$data['sourceName'] = 'sage-open-items-sample.xls';
$data['importedAt'] = '2026-10-26T00:00:00+00:00';
$data['sourceMtime'] = 0;
file_put_contents($dir . '/sage-items.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT));

// Relação cliente → banco FICTÍCIA (só os clientes têm banco; 9004 fica sem banco e a linha BPCNUM 0 é ignorada)
$cs = new Spreadsheet();
$cw = $cs->getActiveSheet();
$cw->setTitle('Export Worksheet');
foreach (['BPCNUM_0', 'BPCNAM_0', 'PAYBAN_0', 'DES_0'] as $c => $v) {
    $cw->setCellValue([$c + 1, 1], $v);
}
foreach ([[0, 'Empresa Exemplo, S.A.', 'B0', 'Banco Exemplo A'], [9001, 'Cliente Alfa, S.A.', 'BA', 'Banco Exemplo A'], [9002, 'Cliente Beta GmbH', 'BB', 'Banco Exemplo B'], [9003, 'Cliente Gama, Lda.', 'BB', 'Banco Exemplo B']] as $r => $row) {
    foreach ($row as $c => $v) {
        $cw->setCellValue([$c + 1, $r + 2], $v);
    }
}
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($cs))->save($dir . '/clientes-bancos-sample.xlsx');
$cli = App\Treasury\ClientesImporter::parse($dir . '/clientes-bancos-sample.xlsx');
$cli['warnings'] = [];
$cli['sourceName'] = 'clientes-bancos-sample.xlsx';
$cli['importedAt'] = '2026-10-26T00:00:00+00:00';
file_put_contents($dir . '/clientes-bancos.json', json_encode($cli, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT));

$state = [
    'version' => 1,
    'settings' => ['inicio' => '', 'horiz' => '10', 'vista' => 'Semanal', 'useCCC' => false, 'netting' => true, 'incPend' => true, 'incOut' => true, 'incRec' => true, 'ruleR' => '0', 'ruleP' => '0',
        'defBankR' => '', 'defBankP' => '', 'modoP' => 'Sugerido', 'crit' => 'Maior folga'],
    'banks' => [
        ['id' => 'ba', 'nome' => 'Banco Exemplo A', 'saldo' => 25000.0, 'ccc' => 50000.0],
        ['id' => 'bb', 'nome' => 'Banco Exemplo B', 'saldo' => 4000.0, 'ccc' => 0.0],
        ['id' => 'bc', 'nome' => 'Banco Exemplo C', 'saldo' => 0.0, 'ccc' => 0.0],
    ],
    'partyBank' => new stdClass(), // os bancos dos clientes vêm de clientes-bancos.json; os fornecedores não têm banco
    'classOv' => new stdClass(),
    'prio' => ['ba', 'bb', 'bc'],
    'recs' => [
        ['id' => 'rdemo1', 'desc' => 'Renda do armazém (exemplo)', 'cat' => 'Renda', 'tipo' => 'P', 'valor' => 2500.0, 'banco' => '', 'freq' => 'Mensal', 'dia' => 1, 'ini' => '2026-11-01', 'fim' => '', 'fds' => 'Adiar para dia útil', 'ativo' => true],
    ],
    'manual' => [
        ['id' => 'mdemo1', 'tipo' => 'P', 'tdoc' => 'Out', 'doc' => 'Salários (exemplo)', 'parte' => 'Salários', 'party' => '', 'venc' => '2026-10-30', 'valor' => 18000.0, 'banco' => 'ba', 'dataPlan' => '', 'origem' => 'Fora do Sage · Contas', 'flag' => ''],
        ['id' => 'mdemo2', 'tipo' => 'P', 'tdoc' => 'Man', 'doc' => 'Prestação leasing (exemplo)', 'parte' => 'Leasing Exemplo', 'party' => '', 'venc' => '2026-11-15', 'valor' => 1200.0, 'banco' => 'bb', 'dataPlan' => '', 'origem' => 'Manual', 'flag' => ''],
    ],
    'overrides' => new stdClass(),
];
file_put_contents($dir . '/treasury.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
echo count($data['items']) . " itens de exemplo\n";
