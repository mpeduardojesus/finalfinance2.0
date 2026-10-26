// Testes do núcleo de cálculo. Dados 100% sintéticos (valores de exemplo).
// Executar: npm test   (esbuild + node --test)
import { test } from "node:test";
import assert from "node:assert/strict";
import {
  Bank, Item, Tipo, SEM, parseNum, parseDate, dayNum, isoOf, fmtD, dm, mondayOf, isoWeek, eur, fmtIn,
  csvQ, splitLine, detectDelim, project, importDocs, importBanks, exportDocs, exportBanks,
} from "../src/tesouraria-core.ts";

const bk = (id: string, nome: string, saldo: string, ccc = "0,00"): Bank => ({ id, nome, saldo, ccc });
let k = 0;
const it = (tipo: Tipo, venc: string, valor: number, banco = "a", dataPlan = ""): Item =>
  ({ id: "t" + ++k, tipo, doc: "D" + k, parte: "P" + k, venc, valor, banco, dataPlan, origem: "teste" });
const P = (o: Partial<{ inicio: string; horiz: string; vista: string; useCCC: boolean }> = {}) =>
  ({ inicio: "2026-10-26", horiz: "4", vista: "Semanal", useCCC: false, ...o });
const bankRes = (r: ReturnType<typeof project>, id: string) => r.res.find((x) => x.id === id)!;

// ───────────── parseNum ─────────────
test("T01 parseNum: formatos PT/EN válidos", () => {
  const casos: [string, number][] = [
    ["1234,56", 1234.56], ["1.234,56", 1234.56], ["1,234.56", 1234.56], ["1 234,56", 1234.56],
    ["1\u00a0234,56", 1234.56], ["€ 12,5", 12.5], ["12,5 €", 12.5], ["-10,5", -10.5], ["(10,50)", -10.5],
    ["1.234.567", 1234567], ["0,01", 0.01], ["1234.5", 1234.5], ["7", 7],
  ];
  for (const [s, v] of casos) assert.equal(parseNum(s), v, s);
});
test("T02 parseNum: entradas inválidas devolvem null", () => {
  for (const s of ["", "  ", "abc", "1,2,3", "1.2.3", "--5", "Infinity", "1,234.5.6", "−5"]) assert.equal(parseNum(s), null, JSON.stringify(s));
});
test("T03 [RISCO R-07] parseNum: ambiguidade '1.500' = 1500 e aceita notação hex/exponencial", () => {
  assert.equal(parseNum("1.500"), 1500);      // pode ser 1,5 em notação inglesa
  assert.equal(parseNum("1.5"), 1.5);
  assert.equal(parseNum("1e3"), 1000);        // aceite (não pretendido)
  assert.equal(parseNum("0x10"), 16);         // aceite (não pretendido)
});

// ───────────── datas ─────────────
test("T04 parseDate: formatos válidos", () => {
  assert.equal(parseDate("2026-10-26"), "2026-10-26");
  assert.equal(parseDate("26/10/2026"), "2026-10-26");
  assert.equal(parseDate("26-10-2026"), "2026-10-26");
  assert.equal(parseDate("26.10.2026"), "2026-10-26");
  assert.equal(parseDate("1/2/2026"), "2026-02-01");
  assert.equal(parseDate("26/10/26"), "2026-10-26");
  assert.equal(parseDate(" 29/02/2028 "), "2028-02-29");
});
test("T05 parseDate: datas inválidas", () => {
  for (const s of ["", "31/04/2026", "29/02/2027", "32/01/2026", "00/01/2026", "2026-13-01", "26/10", "abc", "10/26/2026"]) assert.equal(parseDate(s), null, s);
});
test("T06 semanas ISO e segunda-feira", () => {
  assert.equal(isoOf(mondayOf(dayNum("2026-10-26"))), "2026-10-26"); // segunda
  assert.equal(isoOf(mondayOf(dayNum("2026-10-30"))), "2026-10-26"); // sexta
  assert.equal(isoOf(mondayOf(dayNum("2026-11-01"))), "2026-10-26"); // domingo
  assert.equal(isoOf(mondayOf(dayNum("2026-11-02"))), "2026-11-02");
  assert.equal(isoWeek(dayNum("2026-10-26")), 44);
  assert.equal(isoWeek(dayNum("2026-01-01")), 1);
  assert.equal(isoWeek(dayNum("2027-01-01")), 53);  // sexta 01/01/2027 pertence à semana 53 de 2026
  assert.equal(isoWeek(dayNum("2024-12-30")), 1);   // segunda 30/12/2024 = semana 1 de 2025
  assert.equal(isoWeek(dayNum("2020-12-31")), 53);
  assert.equal(isoWeek(dayNum("2021-01-03")), 53);
  assert.equal(isoWeek(dayNum("2021-01-04")), 1);
});
test("T07 fmtD / dm", () => {
  assert.equal(fmtD("2026-10-05"), "05/10/2026");
  assert.equal(dm(dayNum("2026-10-05")), "05/10");
});

// ───────────── formatação ─────────────
test("T08 formatação eur (pt-PT)", () => {
  assert.equal(eur(1234.5).replace(/\u00a0/g, " "), "1234,50 €");       // pt-PT não agrupa 4 dígitos
  assert.equal(eur(12345.5).replace(/\u00a0|\u202f/g, " "), "12 345,50 €");
  assert.equal(fmtIn(1234.5), "1234,50");
  assert.equal(fmtIn(0), "0,00");
});

// ───────────── CSV ─────────────
test("T09 splitLine/csvQ/detectDelim", () => {
  assert.deepEqual(splitLine('a;"b;c";"d""e";', ";"), ["a", "b;c", 'd"e', ""]);
  assert.equal(csvQ('x;y'), '"x;y"');
  assert.equal(csvQ('a"b'), '"a""b"');
  assert.equal(csvQ("plain"), "plain");
  assert.equal(detectDelim("a\tb;c"), "\t");
  assert.equal(detectDelim("a;b"), ";");
  assert.equal(detectDelim("a,b"), ",");
});

// ───────────── projeção ─────────────
test("T10 projeção básica: saldo + recebimentos - pagamentos por semana", () => {
  const r = project([bk("a", "Banco A", "1000,00")], [
    it("R", "2026-10-28", 500), it("P", "2026-10-29", 300), it("P", "2026-11-03", 1000),
  ], P());
  const a = bankRes(r, "a");
  assert.deepEqual(a.rec.slice(0, 3), [500, 0, 0]);
  assert.deepEqual(a.pag.slice(0, 3), [300, 1000, 0]);
  assert.deepEqual(a.close.slice(0, 3), [1200, 200, 200]);
  assert.equal(a.final, 200);
  assert.equal(a.min, 200);
  assert.equal(a.need, 0);
  assert.equal(a.first, null);
});
test("T11 identidade: final = saldo + Σrec − Σpag", () => {
  const items = [it("R", "2026-10-27", 123.45), it("P", "2026-10-28", 50.1), it("P", "2026-11-10", 20), it("R", "2026-11-12", 7.77)];
  const a = bankRes(project([bk("a", "A", "10,00")], items, P()), "a");
  assert.ok(Math.abs(a.final - (10 + 123.45 + 7.77 - 50.1 - 20)) < 1e-9);
  assert.equal(a.close[a.close.length - 1], a.final);
});
test("T12 data planeada substitui vencimento", () => {
  const r = project([bk("a", "A", "0,00")], [it("R", "2026-10-27", 100, "a", "2026-11-10")], P());
  const a = bankRes(r, "a");
  assert.deepEqual(a.rec.slice(0, 3), [0, 0, 100]);
  assert.equal(a.list[0].n, dayNum("2026-11-10"));
});
test("T13 em atraso: entra no 1.º período, no dia de início", () => {
  const r = project([bk("a", "A", "100,00")], [it("P", "2026-09-01", 40)], P({ inicio: "2026-10-28" }));
  const e = bankRes(r, "a").list[0];
  assert.equal(e.atraso, true);
  assert.equal(e.eff, dayNum("2026-10-28"));
  assert.equal(e.p, 0);
  assert.equal(bankRes(r, "a").pag[0], 40);
});
test("T14 limites de semana: domingo na semana 1, segunda na semana 2, fora do horizonte", () => {
  const r = project([bk("a", "A", "0,00")], [
    it("R", "2026-11-01", 1), it("R", "2026-11-02", 10), it("R", "2026-11-22", 100), it("R", "2026-11-23", 1000),
  ], P({ horiz: "4" }));
  const a = bankRes(r, "a");
  assert.deepEqual(a.rec, [1, 10, 0, 100]);   // 4 semanas: 26/10, 02/11, 09/11, 16/11 (+7 dias → 22/11 cabe)
  assert.equal(r.fora, 1);
  assert.equal(r.foraV, 1000);
  assert.equal(r.periods.length, 4);
  assert.equal(r.periods[0].short, "S44");
});
test("T15 semana começa na segunda-feira mesmo que o início seja a meio da semana", () => {
  const r = project([bk("a", "A", "0,00")], [it("R", "2026-10-30", 5)], P({ inicio: "2026-10-29" }));
  assert.equal(r.periods[0].label.startsWith("Sem. 44 · 26/10–01/11"), true);
  assert.equal(bankRes(r, "a").rec[0], 5);
});
test("T16 vista diária: períodos = dias, a partir da data de início", () => {
  const r = project([bk("a", "A", "0,00")], [it("R", "2026-10-27", 5), it("P", "2026-10-28", 2)], P({ vista: "Diária", horiz: "4" }));
  assert.equal(r.periods.length, 28);
  assert.equal(r.periods[0].label, "seg 26/10");
  const a = bankRes(r, "a");
  assert.equal(a.rec[1], 5); assert.equal(a.pag[2], 2);
  assert.deepEqual(a.close.slice(0, 4), [0, 5, 3, 3]);
});
test("T17 no mesmo dia, recebimentos antes de pagamentos", () => {
  const r = project([bk("a", "A", "0,00")], [it("P", "2026-10-27", 100), it("R", "2026-10-27", 100)], P());
  const a = bankRes(r, "a");
  assert.equal(a.min, 0); assert.equal(a.need, 0); assert.equal(a.first, null);
  assert.equal(a.list[0].item.tipo, "R");
});
test("T18 falta de fundos: mínimo, 1.ª falta e necessidade máxima", () => {
  const r = project([bk("a", "A", "100,00")], [
    it("P", "2026-10-27", 150), it("R", "2026-10-29", 500), it("P", "2026-11-04", 700),
  ], P());
  const a = bankRes(r, "a");
  assert.equal(a.min, -250);
  assert.equal(a.need, 250);
  assert.equal(a.first, dayNum("2026-10-27"));
  assert.deepEqual(a.list.map((e) => e.saldo), [-50, 450, -250]);
});
test("T19 CCC: sem toggle é falta; com toggle usa a linha e só falta acima do limite", () => {
  const banks = [bk("a", "A", "100,00", "200,00")];
  const items = [it("P", "2026-10-27", 250)];
  const off = bankRes(project(banks, items, P({ useCCC: false })), "a");
  assert.equal(off.need, 150); assert.equal(off.lim, 0);
  const on = bankRes(project(banks, items, P({ useCCC: true })), "a");
  assert.equal(on.lim, 200); assert.equal(on.need, 0); assert.equal(on.min, -150); assert.equal(on.first, null);
  const on2 = bankRes(project(banks, [it("P", "2026-10-27", 450)], P({ useCCC: true })), "a");
  assert.equal(on2.need, 150); assert.equal(on2.first, dayNum("2026-10-27"));
});
test("T20 saldo inicial negativo sem CCC: 1.ª falta = data de início", () => {
  const a = bankRes(project([bk("a", "A", "-10,00")], [], P()), "a");
  assert.equal(a.first, dayNum("2026-10-26")); assert.equal(a.need, 10);
});
test("T21 grupo 'Sem banco atribuído': criado só quando há itens; saldo 0; sem CCC", () => {
  const r0 = project([bk("a", "A", "5,00")], [it("P", "2026-10-27", 1)], P());
  assert.equal(r0.res.some((x) => x.id === SEM), false);
  const r = project([bk("a", "A", "5,00")], [it("P", "2026-10-27", 40, ""), it("P", "2026-10-27", 1, "inexistente"), it("R", "2026-10-28", 3, "")], P({ useCCC: true }));
  const s = bankRes(r, SEM);
  assert.equal(s.nome, "Sem banco atribuído");
  assert.equal(s.pagT, 41); assert.equal(s.recT, 3); assert.equal(s.need, 41); assert.equal(s.saldo, 0);
});
test("T22 itens inválidos (valor ≤ 0, data inválida) não entram no cálculo e são contados", () => {
  const bad = [it("P", "2026-10-27", 0), it("P", "2026-10-27", -5), it("P", "xx", 5), it("P", "", 5)];
  const r = project([bk("a", "A", "1,00")], bad, P());
  assert.equal(r.invalid, 4); assert.equal(bankRes(r, "a").pagT, 0);
});
test("T23 dados vazios: sem bancos, sem itens", () => {
  const r = project([], [], P());
  assert.equal(r.res.length, 0); assert.equal(r.periods.length, 4); assert.equal(r.fora, 0);
});
test("T24 data de início inválida → usa DEFAULT_START; horizonte inválido → 6", () => {
  const r = project([bk("a", "A", "0,00")], [], P({ inicio: "lixo", horiz: "x" }));
  assert.equal(r.startN, dayNum("2026-10-26")); assert.equal(r.periods.length, 6);
});
test("T25 horizontes 4–12 semanas (semanal e diária)", () => {
  for (const h of ["4", "6", "8", "10", "12"]) {
    assert.equal(project([], [], P({ horiz: h })).periods.length, Number(h));
    assert.equal(project([], [], P({ horiz: h, vista: "Diária" })).periods.length, Number(h) * 7);
  }
});
test("T26 saldo/CCC inválido ou vazio no banco é tratado como 0", () => {
  const a = bankRes(project([bk("a", "A", "abc", "")], [it("P", "2026-10-27", 1)], P()), "a");
  assert.equal(a.saldo, 0); assert.equal(a.ccc, 0);
});
test("T27 duplicados exatos não são deduplicados (somam)", () => {
  const x = it("P", "2026-10-27", 10);
  const a = bankRes(project([bk("a", "A", "100,00")], [x, { ...x, id: "dup" }], P()), "a");
  assert.equal(a.pagT, 20);
});
test("T28 vários bancos independentes", () => {
  const r = project([bk("a", "A", "10,00"), bk("b", "B", "20,00")], [it("P", "2026-10-27", 15, "a"), it("R", "2026-10-27", 5, "b")], P());
  assert.equal(bankRes(r, "a").final, -5); assert.equal(bankRes(r, "b").final, 25);
  assert.equal(bankRes(r, "a").need, 5); assert.equal(bankRes(r, "b").need, 0);
});
test("T29 itens antes da segunda-feira da 1.ª semana contam como em atraso (semanal)", () => {
  const r = project([bk("a", "A", "0,00")], [it("R", "2026-10-20", 7)], P());
  assert.equal(bankRes(r, "a").rec[0], 7); assert.equal(bankRes(r, "a").list[0].atraso, true);
});
test("T30 semana de transição de ano (53→1)", () => {
  const r = project([], [], P({ inicio: "2026-12-28", horiz: "4" }));
  assert.deepEqual(r.periods.map((p) => p.short), ["S53", "S1", "S2", "S3"]);
});

// ───────────── importação / exportação ─────────────
const bs = [bk("a", "Banco A", "100,00"), bk("b", "Banco B", "0,00")];
test("T31 importar documentos (;): cabeçalho, vírgula decimal, datas DD/MM/AAAA, data planeada", () => {
  const txt = "tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem\n" +
    "Pagamento;FT 1;Fornecedor X;30/11/2026;1500,00;Banco A;;Manual\n" +
    "Recebimento;FR 2;Cliente Y;01/12/2026;2.500,50;banco b;05/12/2026;Teste";
  const r = importDocs(txt, bs, [], false);
  assert.equal(r.errors.length, 0); assert.equal(r.novos.length, 2);
  assert.deepEqual([r.novos[0].tipo, r.novos[0].venc, r.novos[0].valor, r.novos[0].banco], ["P", "2026-11-30", 1500, "a"]);
  assert.deepEqual([r.novos[1].tipo, r.novos[1].valor, r.novos[1].banco, r.novos[1].dataPlan], ["R", 2500.5, "b", "2026-12-05"]);
});
test("T32 importar com tabulação (colagem do Excel)", () => {
  const r = importDocs("Pagamento\tFT 1\tX\t30/11/2026\t10,5\tBanco A\t\tExcel", bs, [], false);
  assert.equal(r.novos.length, 1); assert.equal(r.novos[0].valor, 10.5);
});
test("T33 importar: linhas inválidas são ignoradas e reportadas", () => {
  const txt = "Pagamento;a;b;31/04/2026;1,00;;;\nOutro;a;b;01/05/2026;1,00;;;\nPagamento;a;b;01/05/2026;0;;;\nPagamento;a;b;01/05/2026;xx;;;\nPagamento;a;b;01/05/2026;1;;99/99/2026;\nPagamento;ok;b;01/05/2026;1;;;";
  const r = importDocs(txt, bs, [], false);
  assert.equal(r.novos.length, 1); assert.deepEqual(r.errors, ["linha 1", "linha 2", "linha 3", "linha 4", "linha 5"]);
});
test("T34 importar: banco desconhecido cria banco novo (saldo 0); 'Sem banco' e vazio ficam sem banco", () => {
  const r = importDocs("Pagamento;a;b;01/05/2026;1;Banco Novo;;\nPagamento;a;b;01/05/2026;1;Sem banco;;\nPagamento;a;b;01/05/2026;1;;;", bs, [], false);
  assert.equal(r.banks.length, 3); assert.equal(r.banks[2].saldo, "0,00");
  assert.deepEqual(r.novos.map((n) => n.banco === "" ), [false, true, true]);
});
test("T35 acrescentar vs substituir", () => {
  const base = [it("P", "2026-10-27", 1)];
  const add = importDocs("Pagamento;a;b;01/05/2026;1;;;", bs, base, false);
  const rep = importDocs("Pagamento;a;b;01/05/2026;1;;;", bs, base, true);
  assert.equal(add.items.length, 2); assert.equal(rep.items.length, 1);
});
test("T36 exportar → importar (round-trip) preserva os dados", () => {
  const items: Item[] = [
    { id: "1", tipo: "P", doc: 'FT "1"; A', parte: "Forn; X", venc: "2026-11-30", valor: 1234.56, banco: "a", dataPlan: "2026-12-05", origem: "Manual" },
    { id: "2", tipo: "R", doc: "", parte: "", venc: "2026-11-01", valor: 0.5, banco: "", dataPlan: "", origem: "REC" },
  ];
  const txt = exportDocs(items, bs);
  const r = importDocs(txt, bs, [], true);
  assert.equal(r.errors.length, 0);
  assert.deepEqual(r.novos.map(({ id, ...x }) => x), items.map(({ id, ...x }) => x));
});
test("T37 importar saldos: acrescentar atualiza por nome (sem distinguir maiúsculas)", () => {
  const r = importBanks("banco;saldo;ccc_disponivel\nbanco a;1.000,00;500\nBanco C;5;", bs, [], false);
  assert.equal(r.n, 2); assert.equal(r.banks.length, 3);
  assert.equal(r.banks[0].saldo, "1000,00"); assert.equal(r.banks[0].ccc, "500,00");
  assert.equal(r.banks[2].ccc, "0,00");
});
test("T38 importar saldos substituir: bancos ausentes desaparecem e os seus documentos ficam sem banco; ids preservados", () => {
  const items = [it("P", "2026-10-27", 1, "a"), it("P", "2026-10-27", 1, "b")];
  const alfa = [bk("a", "Alfa", "100,00"), bk("b", "Beta", "0,00")];
  const r = importBanks("Alfa;1;0", alfa, items, true);
  assert.equal(r.banks.length, 1); assert.equal(r.banks[0].id, "a");
  assert.deepEqual(r.items.map((x) => x.banco), ["a", ""]);
});
test("T39 exportar saldos", () => {
  assert.equal(exportBanks(bs), "banco;saldo;ccc_disponivel\nBanco A;100,00;0,00\nBanco B;0,00;0,00");
});

// ───────────── DEFEITOS / RISCOS CARACTERIZADOS ─────────────
// Cada teste abaixo documenta o comportamento ATUAL (defeituoso). Se o defeito for corrigido, o teste falha e deve ser atualizado.
test("B01 [Média] arredondamento de vírgula flutuante gera falsa 'falta de fundos' de ~1e-17", () => {
  const a = bankRes(project([bk("a", "A", "0,30")], [it("P", "2026-10-27", 0.1), it("P", "2026-10-27", 0.2)], P()), "a");
  assert.ok(a.min < 0 && a.min > -1e-9);      // devia ser exatamente 0
  assert.ok(a.need > 0);                      // falsa falta de fundos
  assert.notEqual(a.first, null);
  assert.equal(eur(a.min).replace(/\u00a0/g, " "), "-0,00 €"); // apresentado como -0,00 €
});
test("B02 [Média] importar 'Substituir tudo' com 0 linhas válidas apaga todos os documentos", () => {
  const base = [it("P", "2026-10-27", 1), it("R", "2026-10-27", 1)];
  const r = importDocs("tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem", bs, base, true);
  assert.equal(r.items.length, 0);
  const r2 = importDocs("lixo\noutro lixo", bs, base, true);
  assert.equal(r2.items.length, 0);
});
test("B03 [Média] valor negativo na importação é convertido em positivo (nota de crédito vira pagamento/recebimento)", () => {
  const r = importDocs("Pagamento;NC 1;X;01/12/2026;-100,00;;;", bs, [], false);
  assert.equal(r.novos[0].valor, 100);
});
test("B04 [Baixa] banco cujo nome começa por 'Banco' perde a 1.ª linha da importação de saldos (tomada por cabeçalho)", () => {
  const r = importBanks("Banco Exemplo;1000,00;0\nOutro;5;0", [], [], true);
  assert.equal(r.n, 1);                        // devia ser 2
  assert.equal(r.banks[0].nome, "Outro");
});
test("B05 [Baixa] importar documentos duas vezes (acrescentar) duplica tudo; não há deteção de duplicados", () => {
  const txt = "Pagamento;a;b;01/12/2026;10;;;";
  const r1 = importDocs(txt, bs, [], false);
  const r2 = importDocs(txt, bs, r1.items, false);
  assert.equal(r2.items.length, 2);
});
test("B06 [Média] separador vírgula + vírgula decimal: colunas deslocam-se e a linha é importada ERRADA em silêncio", () => {
  const r = importDocs("Pagamento,FT,X,01/12/2026,1234,56,,,", bs, [], false);
  assert.equal(r.errors.length, 0);            // sem erro reportado
  assert.equal(r.novos[0].valor, 1234);        // devia ser 1234,56
  assert.equal(r.banks.some((b) => b.nome === "56"), true); // e cria um banco fantasma "56"
});
test("B07 [Baixa] deteção do separador só olha para a 1.ª linha", () => {
  const r = importDocs("Pagamento;a;b;01/12/2026;10;;;\nPagamento\ta\tb\t01/12/2026\t10", bs, [], false);
  assert.equal(r.novos.length, 1);             // a 2.ª linha (tabulação) falha: com ';' tem 1 coluna
  assert.equal(r.errors.length, 1);
});
test("B08 [Baixa] campo com quebra de linha entre aspas quebra o round-trip", () => {
  const items: Item[] = [{ id: "1", tipo: "P", doc: "linha1\nlinha2", parte: "X", venc: "2026-11-30", valor: 1, banco: "", dataPlan: "", origem: "m" }];
  const r = importDocs(exportDocs(items, bs), bs, [], true);
  assert.equal(r.novos.length, 0);
});
test("B09 [Baixa] 'linha N' no relatório de erros conta só linhas não vazias (não a linha real do texto)", () => {
  const r = importDocs("\n\nPagamento;a;b;xx;1;;;", bs, [], false);
  assert.deepEqual(r.errors, ["linha 1"]);     // a linha real é a 3
});
test("B10 [Baixa] 'Bancos em falta' do quadro semanal só olha ao fecho do período; perde défices intra-semana", () => {
  const r = project([bk("a", "A", "0,00")], [it("P", "2026-10-26", 100), it("R", "2026-10-30", 100)], P());
  const a = bankRes(r, "a");
  assert.equal(a.close[0], 0);                 // fecho da semana = 0 → quadro não alerta
  assert.equal(a.need, 100);                   // mas o banco tem falta de 100 durante a semana
});
test("B11 [Baixa] 'fora do horizonte' soma recebimentos e pagamentos no mesmo total", () => {
  const r = project([bk("a", "A", "0,00")], [it("R", "2027-06-01", 100), it("P", "2027-06-01", 40)], P());
  assert.equal(r.foraV, 140);
});
test("B12 [Baixa] parseDate aceita ano de 3 dígitos (devolve ISO malformada) e ISO com ano < 100 é mapeado para 20xx", () => {
  assert.equal(parseDate("01/01/202"), "202-01-01");   // ISO malformada (3 dígitos no ano)
  assert.equal(parseDate("0050-01-01"), "2050-01-01");
});
test("B13 [Baixa] recebimentos em atraso são assumidos como recebidos no dia de início (otimista)", () => {
  const r = project([bk("a", "A", "0,00")], [it("R", "2026-01-01", 1000), it("P", "2026-10-26", 1000)], P());
  assert.equal(bankRes(r, "a").need, 0);
});
test("B14 [Baixa] exportação não neutraliza fórmulas (=, +, -, @) ao colar no Excel", () => {
  assert.equal(csvQ("=1+1"), "=1+1");
});
test("T40 desempenho: 20 000 documentos, 12 semanas diárias, < 2 s", () => {
  const items: Item[] = [];
  for (let i = 0; i < 20000; i++) items.push(it(i % 3 ? "P" : "R", isoOf(dayNum("2026-10-26") + (i % 90)), 10 + (i % 50), i % 2 ? "a" : "b"));
  const t0 = Date.now();
  project([bk("a", "A", "1000,00"), bk("b", "B", "0,00")], items, P({ horiz: "12", vista: "Diária" }));
  assert.ok(Date.now() - t0 < 2000);
});
