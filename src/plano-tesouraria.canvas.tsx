import {
  Page, Section, Text, Table, Button, Input, Select, Segmented, Toggle, Callout, Grid,
  LineChart, ColumnSeries, TableOfContents, Status, Rows, useGrokTheme, useState, useMemo, Fragment,
} from "grok/canvas";
import type { ChangeEvent, ReactNode, CSSProperties } from "grok/canvas";

type Tipo = "R" | "P";
interface Bank { id: string; nome: string; saldo: string; ccc: string }
interface Item { id: string; tipo: Tipo; doc: string; parte: string; venc: string; valor: number; banco: string; dataPlan: string; origem: string }
interface Draft { doc: string; parte: string; venc: string; valor: string; banco: string; dataPlan: string; isNew: boolean }
interface Period { label: string; short: string }
interface Ev { n: number; eff: number; atraso: boolean; p: number; item: Item; saldo: number }
interface Filt { banco: string; q: string; all: boolean }

const SEM = "__sem";
const TODOS = "__todos";
const TOTAL = "__total";
const DEFAULT_START = "2026-10-26";
const W44 = "2026-10-30";
const D_ATRASO = "2026-10-26";

// DADOS DE EXEMPLO SINTÉTICOS (fictícios). Nenhum valor, entidade ou contrato deste ficheiro provém de dados reais.
// Para usar dados reais, importe-os em runtime (secção «Importar e exportar») ou substitua estas tabelas localmente, sem as enviar para o repositório.
const SEED_BANKS: [string, string, number, number][] = [["a","Banco A",100000.0,500000],["b","Banco B",20000.0,200000],["c","Banco C",5000.0,0],["d","Banco D",0,0]];
const REC: [string, number, string, number][] = [["Cliente 01",20780.0,"b",0],["Cliente 02",24900.0,"a",0],["Cliente 03",6200.0,"a",0],["Cliente 04",23210.0,"a",0],["Cliente 05",54760.0,"b",0],["Cliente 06",4170.0,"",0],["Cliente 07",26250.0,"b",1],["Cliente 08",7260.0,"",0]];
const ORIG: string[] = ["G·Alfa","G·Beta","ESTR"];
const FORN: [string, number, number, number][] = [["Fornecedor 01",630.0,0,0],["Fornecedor 02",5710.0,0,2],["Fornecedor 03",5310.0,0,0],["Fornecedor 04",510.0,0,1],["Fornecedor 05",3830.0,0,2],["Fornecedor 06",2850.0,0,0],["Fornecedor 07",5280.0,0,1],["Fornecedor 08",970.0,0,2],["Fornecedor 09",630.0,0,1],["Fornecedor 10",6160.0,1,1],["Fornecedor 11",4240.0,1,1],["Fornecedor 12",2770.0,0,2],["Fornecedor 13",7040.0,0,2],["Fornecedor 14",2770.0,1,1]];
const CTAS: [string, string, string, number, string][] = [["Financiamento · pendente","Prestação de exemplo","2026-10-29",5000.0,"a"],["Encargos financeiros · pago","Comissão de exemplo","2026-10-26",50.0,"b"],["Outros pagamentos · pendente","Serviço de exemplo","2026-10-28",1200.0,"c"],["Outros pagamentos · pendente","Pagamento em atraso de exemplo","2026-09-29",800.0,"c"],["Ordenados e salários · pendente","Salários (exemplo)","2026-10-29",60000.0,"a"]];
const FIN: [string, string, string, number, number, number][] = [["a","LEASING EXEMPLO","0000001",5,700.0,700.0],["a","EMPRÉSTIMO EXEMPLO","0000002",15,9000.0,9000.0],["b","LEASING EXEMPLO","0000003",10,450.0,450.0],["c","EMPRÉSTIMO EXEMPLO","0000004",20,2500.0,2500.0]];

function fmtIn(n: number): string { return n.toFixed(2).replace(".", ","); }
function seedBanks(): Bank[] { return SEED_BANKS.map((r) => ({ id: r[0], nome: r[1], saldo: fmtIn(r[2]), ccc: fmtIn(r[3]) })); }
function seedItems(): Item[] {
  const out: Item[] = [];
  let k = 0;
  const mk = (tipo: Tipo, doc: string, parte: string, venc: string, valor: number, banco: string, origem: string) => {
    k += 1;
    out.push({ id: "x" + k, tipo, doc, parte, venc, valor, banco, dataPlan: "", origem });
  };
  for (const r of REC) mk("R", "Previsto sem. 44 (26–31 Out)", r[0], W44, r[1], r[2], r[3] ? "Recebimentos (exemplo B)" : "Recebimentos (exemplo A)");
  for (const r of FORN) {
    const o = ORIG[r[3]];
    mk("P", r[2] ? "Em atraso / vencido" : "Vence sem. 44 (26–31 Out)", r[0], r[2] ? D_ATRASO : W44, r[1], "", o === "ESTR" ? "Fornec. estrangeiros (exemplo)" : "Fornec. gerais · " + o.slice(2));
  }
  for (const r of CTAS) mk("P", r[0], r[1], r[2], r[3], r[4], "Contas sem. 44");
  for (const f of FIN) {
    const dd = f[3] < 10 ? "0" + f[3] : String(f[3]);
    mk("P", "Prestação Nov 2026 · contrato " + f[2], f[1], "2026-11-" + dd, f[4], f[0], "Plano financeiro");
    mk("P", "Prestação Dez 2026 · contrato " + f[2], f[1], "2026-12-" + dd, f[5], f[0], "Plano financeiro");
  }
  return out;
}
let uidCounter = 0;
function uid(): string { uidCounter += 1; return "u" + Date.now().toString(36) + uidCounter; }

function parseNum(s: string): number | null {
  let t = s.replace(/[\s\u00a0€]/g, "");
  if (t === "") return null;
  let neg = false;
  if (/^\(.*\)$/.test(t)) { neg = true; t = t.slice(1, -1); }
  const hasC = t.indexOf(",") >= 0;
  const hasD = t.indexOf(".") >= 0;
  if (hasC && hasD) {
    if (t.lastIndexOf(",") > t.lastIndexOf(".")) t = t.replace(/\./g, "").replace(",", ".");
    else t = t.replace(/,/g, "");
  } else if (hasC) {
    t = t.replace(",", ".");
  } else if (hasD && /^-?\d{1,3}(\.\d{3})+$/.test(t)) {
    t = t.replace(/\./g, "");
  }
  const n = Number(t);
  if (!isFinite(n)) return null;
  return neg ? -n : n;
}
function pad2(n: number): string { return n < 10 ? "0" + n : String(n); }
function validYMD(y: number, m: number, d: number): string | null {
  if (y < 100) y += 2000;
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
  return y + "-" + pad2(m) + "-" + pad2(d);
}
function parseDate(s: string): string | null {
  const t = s.trim();
  let m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t);
  if (m) return validYMD(Number(m[1]), Number(m[2]), Number(m[3]));
  m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/.exec(t);
  if (m) return validYMD(Number(m[3]), Number(m[2]), Number(m[1]));
  return null;
}
function dayNum(iso: string): number {
  const y = Number(iso.slice(0, 4)), m = Number(iso.slice(5, 7)), d = Number(iso.slice(8, 10));
  return Math.round(Date.UTC(y, m - 1, d) / 86400000);
}
function isoOf(n: number): string { return new Date(n * 86400000).toISOString().slice(0, 10); }
function fmtD(iso: string): string { return iso.length === 10 ? iso.slice(8, 10) + "/" + iso.slice(5, 7) + "/" + iso.slice(0, 4) : iso; }
function dm(n: number): string { const s = isoOf(n); return s.slice(8, 10) + "/" + s.slice(5, 7); }
function mondayOf(n: number): number { return n - ((n + 3) % 7); }
function isoWeek(n: number): number {
  const thu = mondayOf(n) + 3;
  const y = new Date(thu * 86400000).getUTCFullYear();
  const jan1 = Math.round(Date.UTC(y, 0, 1) / 86400000);
  return Math.floor((thu - jan1) / 7) + 1;
}
const WD = ["seg", "ter", "qua", "qui", "sex", "sáb", "dom"];
function eur(n: number): string {
  return n.toLocaleString("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " €";
}
function eurK(n: number): string {
  return (n / 1000).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " k€";
}
function sum(a: number[]): number { let s = 0; for (const v of a) s += v; return s; }
function csvQ(s: string): string { return /[;"\n\t]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
function splitLine(line: string, delim: string): string[] {
  const out: string[] = [];
  let cur = "";
  let inQ = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (inQ) {
      if (ch === '"') { if (line[i + 1] === '"') { cur += '"'; i++; } else inQ = false; } else cur += ch;
    } else if (ch === '"') inQ = true;
    else if (ch === delim) { out.push(cur); cur = ""; }
    else cur += ch;
  }
  out.push(cur);
  return out.map((x) => x.trim());
}
function detectDelim(line: string): string { return line.indexOf("\t") >= 0 ? "\t" : line.indexOf(";") >= 0 ? ";" : ","; }

export default function App() {
  const theme = useGrokTheme();
  const [banks, setBanks] = useState<Bank[]>(seedBanks);
  const [items, setItems] = useState<Item[]>(seedItems);
  const [inicio, setInicio] = useState<string>(DEFAULT_START);
  const [horiz, setHoriz] = useState<string>("6");
  const [vista, setVista] = useState<string>("Semanal");
  const [useCCC, setUseCCC] = useState<boolean>(false);
  const [planBank, setPlanBank] = useState<string>("");
  const [showRec, setShowRec] = useState<boolean>(true);
  const [showAllPlan, setShowAllPlan] = useState<boolean>(false);
  const [medida, setMedida] = useState<string>("Saldo final");
  const [chartBank, setChartBank] = useState<string>(TOTAL);
  const [editId, setEditId] = useState<string | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [editMsg, setEditMsg] = useState<string>("");
  const [filtR, setFiltR] = useState<Filt>({ banco: TODOS, q: "", all: false });
  const [filtP, setFiltP] = useState<Filt>({ banco: TODOS, q: "", all: false });
  const [bulkR, setBulkR] = useState<string>("");
  const [bulkP, setBulkP] = useState<string>("");
  const [ioKind, setIoKind] = useState<string>("Documentos");
  const [ioText, setIoText] = useState<string>("");
  const [ioMsg, setIoMsg] = useState<string>("");

  const bankIds = useMemo(() => new Set(banks.map((b) => b.id)), [banks]);
  const bankName = (id: string): string => { const b = banks.find((x) => x.id === id); return b ? b.nome : ""; };
  const startValid = parseDate(inicio);

  const proj = useMemo(() => {
    const startN = dayNum(parseDate(inicio) ?? DEFAULT_START);
    const nW = Number(horiz) || 6;
    const weekly = vista === "Semanal";
    const m0 = mondayOf(startN);
    const periods: Period[] = [];
    if (weekly) {
      for (let k = 0; k < nW; k++) {
        const s = m0 + 7 * k;
        periods.push({ label: "Sem. " + isoWeek(s) + " · " + dm(s) + "–" + dm(s + 6), short: "S" + isoWeek(s) });
      }
    } else {
      for (let k = 0; k < nW * 7; k++) {
        const s = startN + k;
        periods.push({ label: WD[(s + 3) % 7] + " " + dm(s), short: dm(s) });
      }
    }
    const idxOf = (n: number): number => {
      let i = weekly ? Math.floor((n - m0) / 7) : n - startN;
      if (i < 0) i = 0;
      return i >= periods.length ? -1 : i;
    };
    const ids = new Set(banks.map((b) => b.id));
    const cols = banks.map((b) => ({ id: b.id, nome: b.nome.trim() || "(sem nome)", saldo: parseNum(b.saldo) ?? 0, ccc: parseNum(b.ccc) ?? 0 }));
    let fora = 0, foraV = 0, invalid = 0;
    const evs: Record<string, Ev[]> = {};
    for (const it of items) {
      const d = (it.dataPlan ? parseDate(it.dataPlan) : null) ?? parseDate(it.venc);
      if (!d || !(it.valor > 0)) { invalid++; continue; }
      const n = dayNum(d);
      const p = idxOf(n);
      if (p < 0) { fora++; foraV += it.valor; continue; }
      const b = ids.has(it.banco) ? it.banco : SEM;
      if (!evs[b]) evs[b] = [];
      evs[b].push({ n, eff: Math.max(n, startN), atraso: n < startN, p, item: it, saldo: 0 });
    }
    if (evs[SEM]) cols.push({ id: SEM, nome: "Sem banco atribuído", saldo: 0, ccc: 0 });
    const res = cols.map((c) => {
      const list = (evs[c.id] || []).slice().sort((a: Ev, b: Ev) =>
        a.eff - b.eff || (a.item.tipo === b.item.tipo ? 0 : a.item.tipo === "R" ? -1 : 1) || b.item.valor - a.item.valor);
      const lim = useCCC ? c.ccc : 0;
      let run = c.saldo;
      let min = c.saldo;
      let first: number | null = c.saldo + lim < 0 ? startN : null;
      const rec = periods.map(() => 0);
      const pag = periods.map(() => 0);
      for (const e of list) {
        if (e.item.tipo === "R") { run += e.item.valor; rec[e.p] += e.item.valor; }
        else { run -= e.item.valor; pag[e.p] += e.item.valor; }
        e.saldo = run;
        if (run < min) min = run;
        if (first === null && run + lim < 0) first = e.eff;
      }
      const close: number[] = [];
      let r = c.saldo;
      for (let i = 0; i < periods.length; i++) { r += rec[i] - pag[i]; close.push(r); }
      return { id: c.id, nome: c.nome, saldo: c.saldo, ccc: c.ccc, lim, list, rec, pag, close, min, first, need: Math.max(0, -(min + lim)), recT: sum(rec), pagT: sum(pag), final: r };
    });
    return { startN, periods, res, fora, foraV, invalid };
  }, [banks, items, inicio, horiz, vista, useCCC]);

  type Res = (typeof proj.res)[number];
  const estado = (r: Res): ReactNode =>
    r.need > 0 ? <Status tone="danger">Falta de fundos</Status>
      : r.min < 0 ? <Status tone="warning">Recorre à CCC</Status>
        : <Status tone="success">Coberto</Status>;

  const shortBanks = proj.res.filter((r) => r.need > 0);
  const visibleRes = proj.res.filter((r) => r.saldo !== 0 || r.list.length > 0);
  const planSel = proj.res.find((r) => r.id === planBank) ?? (shortBanks.find((r) => r.id !== SEM) ?? proj.res[0]);
  const semRes = proj.res.find((r) => r.id === SEM);

  // ---------- edição de documentos ----------
  const startEdit = (it: Item) => {
    setEditId(it.id);
    setEditMsg("");
    setDraft({ doc: it.doc, parte: it.parte, venc: it.venc ? fmtD(it.venc) : "", valor: it.valor ? fmtIn(it.valor) : "", banco: bankIds.has(it.banco) ? it.banco : SEM, dataPlan: it.dataPlan ? fmtD(it.dataPlan) : "", isNew: false });
  };
  const addItem = (tipo: Tipo) => {
    const prevNew = editId && draft && draft.isNew ? editId : null;
    const id = uid();
    const it: Item = { id, tipo, doc: "", parte: "", venc: parseDate(inicio) ?? DEFAULT_START, valor: 0, banco: "", dataPlan: "", origem: "Manual" };
    setItems((xs: Item[]) => [it, ...xs.filter((x) => x.id !== prevNew)]);
    setEditId(id);
    setEditMsg("");
    setDraft({ doc: "", parte: "", venc: fmtD(it.venc), valor: "", banco: SEM, dataPlan: "", isNew: true });
  };
  const cancelEdit = () => {
    if (editId && draft && draft.isNew) setItems((xs: Item[]) => xs.filter((x) => x.id !== editId));
    setEditId(null); setDraft(null); setEditMsg("");
  };
  const saveEdit = () => {
    if (!editId || !draft) return;
    const v = parseNum(draft.valor);
    const d = parseDate(draft.venc);
    const dp = draft.dataPlan.trim() === "" ? "" : parseDate(draft.dataPlan);
    if (!d) { setEditMsg("Data de vencimento inválida: use DD/MM/AAAA ou AAAA-MM-DD."); return; }
    if (v === null || v <= 0) { setEditMsg("Valor inválido: indique um montante positivo, por exemplo 1234,56."); return; }
    if (dp === null) { setEditMsg("Data planeada inválida: deixe em branco para usar o vencimento."); return; }
    const banco = draft.banco === SEM ? "" : draft.banco;
    setItems((xs: Item[]) => xs.map((x) => x.id === editId ? { ...x, doc: draft.doc.trim(), parte: draft.parte.trim(), venc: d, valor: Math.round(v * 100) / 100, banco, dataPlan: dp } : x));
    setEditId(null); setDraft(null); setEditMsg("");
  };
  const delItem = (id: string) => {
    setItems((xs: Item[]) => xs.filter((x) => x.id !== id));
    if (editId === id) { setEditId(null); setDraft(null); setEditMsg(""); }
  };
  const upd = (k: keyof Draft, v: string) => { if (draft) setDraft({ ...draft, [k]: v }); };

  const bankOpts = banks.map((b) => ({ value: b.id, label: b.nome.trim() || "(sem nome)" }));
  const rowWrap: CSSProperties = { display: "flex", flexWrap: "wrap", gap: 12, alignItems: "flex-end" };
  const cellWrap: CSSProperties = { display: "flex", flexWrap: "wrap", gap: 6 };

  const filtered = (tipo: Tipo, f: Filt): Item[] => {
    const q = f.q.trim().toLowerCase();
    return items.filter((it) => {
      if (it.tipo !== tipo) return false;
      if (it.id === editId) return true;
      const b = bankIds.has(it.banco) ? it.banco : SEM;
      if (f.banco !== TODOS && f.banco !== b) return false;
      if (q && (it.doc + " " + it.parte + " " + it.origem).toLowerCase().indexOf(q) < 0) return false;
      return true;
    }).sort((a, b) => {
      if (a.id === editId) return -1;
      if (b.id === editId) return 1;
      const da = a.dataPlan || a.venc, db = b.dataPlan || b.venc;
      return da < db ? -1 : da > db ? 1 : b.valor - a.valor;
    });
  };

  const docBlock = (tipo: Tipo) => {
    const f = tipo === "R" ? filtR : filtP;
    const setF = tipo === "R" ? setFiltR : setFiltP;
    const bulk = tipo === "R" ? bulkR : bulkP;
    const setBulk = tipo === "R" ? setBulkR : setBulkP;
    const list = filtered(tipo, f);
    const shown = f.all ? list : list.slice(0, 40);
    const allOfType = items.filter((x) => x.tipo === tipo);
    const semList = allOfType.filter((x) => !bankIds.has(x.banco));
    const listSem = list.filter((x) => !bankIds.has(x.banco) && x.id !== editId);
    const editingHere = draft !== null && shown.some((x) => x.id === editId);
    const rows: ReactNode[][] = shown.map((it) => {
      if (it.id === editId && draft) {
        return [
          <Input value={draft.doc} placeholder="Documento" onChange={(v: string) => upd("doc", v)} />,
          <Input value={draft.parte} placeholder={tipo === "R" ? "Cliente" : "Fornecedor"} onChange={(v: string) => upd("parte", v)} />,
          <Input value={draft.venc} placeholder="DD/MM/AAAA" onChange={(v: string) => upd("venc", v)} />,
          <Input value={draft.dataPlan} placeholder="Igual ao vencimento" onChange={(v: string) => upd("dataPlan", v)} />,
          <Input value={draft.valor} placeholder="0,00" onChange={(v: string) => upd("valor", v)} />,
          <Select value={draft.banco} options={[{ value: SEM, label: "Sem banco" }, ...bankOpts]} onChange={(v: string) => upd("banco", v)} />,
          it.origem,
          <div style={cellWrap}><Button label="Guardar" variant="primary" onClick={saveEdit} /><Button label="Cancelar" onClick={cancelEdit} /></div>,
        ];
      }
      const bn = bankIds.has(it.banco) ? bankName(it.banco) : "";
      return [
        it.doc || "—",
        it.parte || "—",
        fmtD(it.venc),
        it.dataPlan ? fmtD(it.dataPlan) + " (alterada)" : "—",
        eur(it.valor),
        bn ? bn : <Status tone="warning">Sem banco</Status>,
        it.origem,
        <div style={cellWrap}><Button label="Editar" onClick={() => startEdit(it)} /><Button label="Apagar" onClick={() => delItem(it.id)} /></div>,
      ];
    });
    return (
      <Fragment>
        <div style={rowWrap}>
          <Button label={tipo === "R" ? "Novo recebimento" : "Novo pagamento"} variant="primary" onClick={() => addItem(tipo)} />
          <Select label="Banco" value={f.banco} options={[{ value: TODOS, label: "Todos" }, { value: SEM, label: "Sem banco" }, ...bankOpts]} onChange={(v: string) => setF({ ...f, banco: v })} />
          <Input label="Pesquisar" value={f.q} placeholder="Documento, entidade ou origem" onChange={(v: string) => setF({ ...f, q: v })} />
          <Toggle label={"Mostrar todas (" + list.length + ")"} value={f.all} onChange={(v: boolean) => setF({ ...f, all: v })} />
        </div>
        {rows.length > 0 ? (
          <Table
            columns={["Documento", tipo === "R" ? "Cliente" : "Fornecedor", "Vencimento", "Data planeada", "Valor", "Banco", "Origem", "Ações"]}
            align={["start", "start", "start", "start", "end", "start", "start", "start"]}
            rows={rows}
          />
        ) : (
          <Text>Nenhum documento corresponde ao filtro atual.</Text>
        )}
        {editingHere && editMsg ? <Text>{editMsg}</Text> : null}
        <Text>{allOfType.length + " documentos · total " + eur(sum(allOfType.map((x) => x.valor))) + " · " + semList.length + " sem banco (" + eur(sum(semList.map((x) => x.valor))) + ")" + (!f.all && list.length > 40 ? " · a mostrar 40 de " + list.length : "")}</Text>
        {semList.length > 0 ? (
          <div style={rowWrap}>
            <Select label="Atribuir banco" value={bulk} placeholder="Escolher banco" options={bankOpts} onChange={(v: string) => setBulk(v)} />
            <Button
              label={"Aplicar às " + listSem.length + " linhas sem banco do filtro"}
              disabled={!bulk || listSem.length === 0 || !bankIds.has(bulk)}
              onClick={() => {
                const ids = new Set(listSem.map((x) => x.id));
                setItems((xs: Item[]) => xs.map((x) => ids.has(x.id) ? { ...x, banco: bulk } : x));
              }}
            />
          </div>
        ) : null}
      </Fragment>
    );
  };

  // ---------- detalhe do plano por banco ----------
  const planRows: ReactNode[][] = [];
  if (planSel) {
    for (let i = 0; i < proj.periods.length; i++) {
      const evs = planSel.list.filter((e) => e.p === i);
      if (evs.length === 0) continue;
      for (const e of evs) {
        if (e.item.tipo === "R" && !showRec) continue;
        const short = e.saldo + planSel.lim < 0;
        planRows.push([
          fmtD(isoOf(e.n)) + (e.atraso ? " · em atraso" : "") + (e.item.dataPlan ? " · data alterada" : ""),
          e.item.tipo === "R" ? "Recebimento" : "Pagamento",
          e.item.doc || "—",
          e.item.parte || "—",
          e.item.tipo === "P" ? eur(e.item.valor) : "",
          e.item.tipo === "R" ? eur(e.item.valor) : "",
          eur(e.saldo),
          e.item.tipo === "P" ? (short ? <Status tone="danger">Falta de fundos</Status> : e.saldo < 0 ? <Status tone="warning">Usa CCC</Status> : "") : "",
        ]);
      }
      const cl = planSel.close[i];
      planRows.push([
        "Total " + proj.periods[i].label,
        "",
        "",
        "",
        eur(planSel.pag[i]),
        eur(planSel.rec[i]),
        eur(cl),
        cl + planSel.lim < 0 ? <Status tone="danger">Saldo negativo</Status> : cl < 0 ? <Status tone="warning">Usa CCC</Status> : <Status tone="success">OK</Status>,
      ]);
    }
  }
  const planShown = showAllPlan ? planRows : planRows.slice(0, 80);

  // ---------- projeção ----------
  const valOf = (r: Res, i: number): number =>
    medida === "Saldo final" ? r.close[i] : medida === "Recebimentos" ? r.rec[i] : medida === "Pagamentos" ? r.pag[i] : r.rec[i] - r.pag[i];
  const projRows: ReactNode[][] = proj.periods.map((p, i) => {
    const alert = proj.res.filter((r) => r.close[i] + r.lim < 0).map((r) => r.nome);
    return [
      p.label,
      ...visibleRes.map((r) => eur(valOf(r, i))),
      eur(sum(proj.res.map((r) => valOf(r, i)))),
      alert.length ? <Status tone="danger">{alert.join(", ")}</Status> : "",
    ];
  });
  const openingRow: ReactNode[] = ["Saldo inicial " + dm(proj.startN), ...visibleRes.map((r) => eur(r.saldo)), eur(sum(proj.res.map((r) => r.saldo))), ""];

  const chartRes = proj.res.find((r) => r.id === chartBank);
  const cClose = chartRes ? chartRes.close : proj.periods.map((_, i) => sum(proj.res.map((r) => r.close[i])));
  const cRec = chartRes ? chartRes.rec : proj.periods.map((_, i) => sum(proj.res.map((r) => r.rec[i])));
  const cPag = chartRes ? chartRes.pag : proj.periods.map((_, i) => sum(proj.res.map((r) => r.pag[i])));
  const nP = proj.periods.length;
  const labelAt = (i: number): string => {
    if (nP <= 10) return proj.periods[i].short;
    const step = Math.max(1, Math.round((nP - 1) / 5));
    return i === 0 || i === nP - 1 || (i % step === 0 && nP - 1 - i >= step / 2) ? proj.periods[i].short : "";
  };
  const cMin = Math.min(0, ...cClose);
  const cMax = Math.max(0, ...cClose);
  const spanC = Math.max(1, cMax - cMin);
  const ceil = cMax + spanC * 0.08;
  const floor = cMin < 0 ? cMin - spanC * 0.08 : 0;

  // ---------- edição de bancos ----------
  const setBank = (id: string, k: "nome" | "saldo" | "ccc", v: string) => setBanks((bs: Bank[]) => bs.map((b) => b.id === id ? { ...b, [k]: v } : b));
  const removeBank = (id: string) => {
    setBanks((bs: Bank[]) => bs.filter((b) => b.id !== id));
    setItems((xs: Item[]) => xs.map((x) => x.banco === id ? { ...x, banco: "" } : x));
    if (planBank === id) setPlanBank("");
    if (chartBank === id) setChartBank(TOTAL);
  };
  const bankRows: ReactNode[][] = banks.map((b) => {
    const okS = parseNum(b.saldo) !== null || b.saldo.trim() === "";
    const okC = parseNum(b.ccc) !== null || b.ccc.trim() === "";
    return [
      <Input value={b.nome} onChange={(v: string) => setBank(b.id, "nome", v)} />,
      <Input value={b.saldo} placeholder="0,00" onChange={(v: string) => setBank(b.id, "saldo", v)} />,
      <Input value={b.ccc} placeholder="0,00" onChange={(v: string) => setBank(b.id, "ccc", v)} />,
      !okS || !okC ? <Status tone="danger">Valor inválido</Status> : "",
      <Button label="Remover" onClick={() => removeBank(b.id)} />,
    ];
  });
  bankRows.push(["Total", eur(sum(banks.map((b) => parseNum(b.saldo) ?? 0))), eur(sum(banks.map((b) => parseNum(b.ccc) ?? 0))), "", ""]);

  // ---------- importar / exportar ----------
  const exportDocs = (): string => ["tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem"].concat(items.map((it) =>
    [it.tipo === "R" ? "Recebimento" : "Pagamento", it.doc, it.parte, fmtD(it.venc), fmtIn(it.valor), bankIds.has(it.banco) ? bankName(it.banco) : "", it.dataPlan ? fmtD(it.dataPlan) : "", it.origem].map(csvQ).join(";"))).join("\n");
  const exportBanks = (): string => ["banco;saldo;ccc_disponivel"].concat(banks.map((b) => [b.nome, fmtIn(parseNum(b.saldo) ?? 0), fmtIn(parseNum(b.ccc) ?? 0)].map(csvQ).join(";"))).join("\n");
  const exportText = ioKind === "Documentos" ? exportDocs() : exportBanks();

  const doImport = (replace: boolean) => {
    const lines = ioText.split(/\r?\n/).filter((l) => l.trim() !== "");
    if (lines.length === 0) { setIoMsg("Cole primeiro os dados no campo de importação."); return; }
    const delim = detectDelim(lines[0]);
    const errors: string[] = [];
    if (ioKind === "Saldos") {
      const next: Bank[] = replace ? [] : banks.map((b) => ({ ...b }));
      let n = 0;
      lines.forEach((l, li) => {
        const c = splitLine(l, delim);
        if (li === 0 && c[0].toLowerCase().startsWith("banco")) return;
        const nome = c[0] || "";
        const s = parseNum(c[1] || "");
        const cc = parseNum(c[2] || "") ?? 0;
        if (!nome || s === null) { errors.push("linha " + (li + 1)); return; }
        const ex = next.find((b) => b.nome.trim().toLowerCase() === nome.toLowerCase());
        const old = banks.find((b) => b.nome.trim().toLowerCase() === nome.toLowerCase());
        if (ex) { ex.saldo = fmtIn(s); ex.ccc = fmtIn(cc); }
        else next.push({ id: old ? old.id : uid(), nome, saldo: fmtIn(s), ccc: fmtIn(cc) });
        n++;
      });
      const ids = new Set(next.map((b) => b.id));
      setBanks(next);
      setItems((xs: Item[]) => xs.map((x) => x.banco && !ids.has(x.banco) ? { ...x, banco: "" } : x));
      setIoMsg(n + " bancos importados" + (errors.length ? "; ignoradas: " + errors.join(", ") : "") + ".");
      return;
    }
    const nb: Bank[] = banks.slice();
    const resolve = (name: string): string => {
      const t = name.trim();
      if (!t || t.toLowerCase() === "sem banco") return "";
      const ex = nb.find((b) => b.nome.trim().toLowerCase() === t.toLowerCase());
      if (ex) return ex.id;
      const b: Bank = { id: uid(), nome: t, saldo: "0,00", ccc: "0,00" };
      nb.push(b);
      return b.id;
    };
    const novos: Item[] = [];
    lines.forEach((l, li) => {
      const c = splitLine(l, delim);
      if (li === 0 && c[0].toLowerCase().startsWith("tipo")) return;
      const t = (c[0] || "").toLowerCase();
      const tipo: Tipo | null = t.startsWith("r") ? "R" : t.startsWith("p") ? "P" : null;
      const venc = parseDate(c[3] || "");
      const v = parseNum(c[4] || "");
      const dp = c[6] ? parseDate(c[6]) : "";
      if (!tipo || !venc || v === null || v === 0 || dp === null) { errors.push("linha " + (li + 1)); return; }
      novos.push({ id: uid(), tipo, doc: c[1] || "", parte: c[2] || "", venc, valor: Math.round(Math.abs(v) * 100) / 100, banco: resolve(c[5] || ""), dataPlan: dp, origem: c[7] || "Importado" });
    });
    setBanks(nb);
    setItems((xs: Item[]) => replace ? novos : xs.concat(novos));
    setEditId(null); setDraft(null);
    setIoMsg(novos.length + " documentos importados" + (replace ? " (lista substituída)" : "") + (nb.length > banks.length ? "; " + (nb.length - banks.length) + " bancos novos criados com saldo 0" : "") + (errors.length ? "; linhas ignoradas: " + errors.slice(0, 12).join(", ") + (errors.length > 12 ? "…" : "") : "") + ".");
  };
  const copyExport = () => {
    try {
      navigator.clipboard.writeText(exportText).then(
        () => setIoMsg("Exportação copiada para a área de transferência."),
        () => setIoMsg("Não foi possível copiar automaticamente: clique no campo, selecione tudo e use Ctrl+C."),
      );
    } catch {
      setIoMsg("Não foi possível copiar automaticamente: clique no campo, selecione tudo e use Ctrl+C.");
    }
  };
  const resetAll = () => {
    setBanks(seedBanks()); setItems(seedItems()); setInicio(DEFAULT_START); setHoriz("6"); setVista("Semanal"); setUseCCC(false);
    setEditId(null); setDraft(null); setPlanBank(""); setChartBank(TOTAL); setIoMsg("Dados de exemplo repostos.");
  };
  const taStyle: CSSProperties = {
    width: "100%", minHeight: 150, boxSizing: "border-box", padding: 10, resize: "vertical",
    fontFamily: theme.font.mono, fontSize: theme.font.code.fontSize, lineHeight: theme.font.code.lineHeight,
    color: theme.text.primary, background: theme.bg.subtle, border: "1px solid " + theme.border.default, borderRadius: theme.radius.md,
  };

  const recTot = sum(proj.res.map((r) => r.recT));
  const pagTot = sum(proj.res.map((r) => r.pagT));

  return (
    <Page
      title="Plano de tesouraria"
      summary="Saldos bancários, recebimentos e pagamentos por data de vencimento, e o plano de pagamentos por banco com alertas de falta de fundos. Dados iniciais de exemplo (fictícios), todos editáveis."
      width="full"
    >
      <TableOfContents items={[
        { label: "Saldos bancários e parâmetros", id: "saldos" },
        { label: "Plano de pagamentos por banco", id: "plano" },
        { label: "Projeção de tesouraria", id: "projecao" },
        { label: "Recebimentos de clientes", id: "recebimentos" },
        { label: "Pagamentos a fornecedores e outros", id: "pagamentos" },
        { label: "Importar e exportar", id: "io" },
        { label: "Origem dos dados", id: "origem" },
      ]} />

      <Section heading="Saldos bancários e parâmetros" id="saldos" caption="Saldos à data de início, em euros. CCC = linha de conta corrente disponível.">
        <Table columns={["Banco", "Saldo inicial (€)", "CCC disponível (€)", "Validação", "Ações"]} align={["start", "start", "start", "start", "start"]} rows={bankRows} />
        <div style={rowWrap}>
          <Button label="Adicionar banco" onClick={() => setBanks((bs: Bank[]) => bs.concat([{ id: uid(), nome: "Novo banco", saldo: "0,00", ccc: "0,00" }]))} />
          <Input label="Data de início" value={inicio} placeholder="AAAA-MM-DD" onChange={(v: string) => setInicio(v)} />
          <Select label="Horizonte" value={horiz} options={[{ value: "4", label: "4 semanas" }, { value: "6", label: "6 semanas" }, { value: "8", label: "8 semanas" }, { value: "10", label: "10 semanas" }, { value: "12", label: "12 semanas" }]} onChange={(v: string) => setHoriz(v)} />
          <Segmented options={["Semanal", "Diária"]} value={vista} onChange={(v: string) => setVista(v)} />
          <Toggle label="Contar CCC como fundos disponíveis" value={useCCC} onChange={(v: boolean) => setUseCCC(v)} />
        </div>
        {!startValid ? <Text>{"Data de início inválida; a usar " + fmtD(DEFAULT_START) + "."}</Text> : null}
        <Text>{"Documentos com data anterior à data de início entram no primeiro período como em atraso. " + (proj.fora > 0 ? proj.fora + " documentos (" + eur(proj.foraV) + ") ficam fora do horizonte. " : "") + (proj.invalid > 0 ? proj.invalid + " documentos sem data ou valor válidos não entram no cálculo." : "")}</Text>
      </Section>

      <Section heading="Plano de pagamentos por banco" id="plano">
        {shortBanks.length > 0 ? (
          <Callout tone="warning" lead="Falta de fundos no horizonte.">
            {shortBanks.map((r) => r.nome + ": primeira falta a " + (r.first !== null ? dm(r.first) : "—") + ", necessidade máxima " + eur(r.need)).join(" · ") + (semRes ? ". Há " + eur(semRes.pagT) + " de pagamentos sem banco atribuído: atribua-os nas tabelas abaixo." : ".")}
          </Callout>
        ) : (
          <Callout tone="success" lead="Todos os bancos cobrem os pagamentos no horizonte.">
            {"Recebimentos de " + eur(recTot) + " e pagamentos de " + eur(pagTot) + " no período."}
          </Callout>
        )}
        <Table
          columns={["Banco", "Saldo inicial", "Recebimentos", "Pagamentos", "Saldo final", "Saldo mínimo", "1.ª falta", "Necessidade máx.", "Estado"]}
          align={["start", "end", "end", "end", "end", "end", "end", "end", "start"]}
          rows={visibleRes.map((r) => [r.nome, eur(r.saldo), eur(r.recT), eur(r.pagT), eur(r.final), eur(r.min), r.first !== null ? dm(r.first) : "—", r.need > 0 ? eur(r.need) : "—", estado(r)])}
        />
        <div style={rowWrap}>
          <Select label="Banco em detalhe" value={planSel ? planSel.id : ""} options={proj.res.map((r) => ({ value: r.id, label: r.nome }))} onChange={(v: string) => setPlanBank(v)} />
          <Toggle label="Mostrar recebimentos" value={showRec} onChange={(v: boolean) => setShowRec(v)} />
          {planRows.length > 80 ? <Toggle label={"Mostrar todas as linhas (" + planRows.length + ")"} value={showAllPlan} onChange={(v: boolean) => setShowAllPlan(v)} /> : null}
        </div>
        {planSel && planShown.length > 0 ? (
          <Table
            columns={["Data", "Tipo", "Documento", "Entidade", "Pagamento", "Recebimento", "Saldo após", "Estado"]}
            align={["start", "start", "start", "start", "end", "end", "end", "start"]}
            rows={planShown}
          />
        ) : null}
        {planSel ? <Text>{planSel.nome + ": saldo inicial " + eur(planSel.saldo) + (useCCC && planSel.ccc ? " mais CCC " + eur(planSel.ccc) : "") + ". No mesmo dia os recebimentos entram antes dos pagamentos; o saldo após cada pagamento mostra onde faltam fundos."}</Text> : null}
      </Section>

      <Section heading="Projeção de tesouraria" id="projecao">
        <div style={rowWrap}>
          <Select label="Gráficos" value={chartBank} options={[{ value: TOTAL, label: "Todos os bancos" }, ...proj.res.map((r) => ({ value: r.id, label: r.nome }))]} onChange={(v: string) => setChartBank(v)} />
        </div>
        <Grid columns={2}>
          <LineChart
            points={cClose.map((v, i) => ({ label: labelAt(i), value: v }))}
            labels={["Saldo final"]}
            ceiling={ceil}
            floor={floor}
            format={eurK}
            annotations={floor < 0 ? [{ at: "zero", label: "0 €" }] : undefined}
          />
          <ColumnSeries
            labels={proj.periods.map((_, i) => labelAt(i))}
            series={[{ name: "Recebimentos", values: cRec }, { name: "Pagamentos", values: cPag }]}
            format={eurK}
          />
        </Grid>
        <div style={rowWrap}>
          <Segmented options={["Saldo final", "Recebimentos", "Pagamentos", "Líquido"]} value={medida} onChange={(v: string) => setMedida(v)} />
        </div>
        <Table
          columns={["Período", ...visibleRes.map((r) => r.nome), "Total", "Bancos em falta"]}
          align={["start", ...visibleRes.map(() => "end" as const), "end", "start"]}
          rows={medida === "Saldo final" ? [openingRow, ...projRows] : projRows}
        />
      </Section>

      <Section heading="Recebimentos de clientes" id="recebimentos">
        {docBlock("R")}
      </Section>

      <Section heading="Pagamentos a fornecedores e outros" id="pagamentos">
        {docBlock("P")}
      </Section>

      <Section heading="Importar e exportar" id="io">
        <div style={rowWrap}>
          <Segmented options={["Documentos", "Saldos"]} value={ioKind} onChange={(v: string) => { setIoKind(v); setIoMsg(""); }} />
          <Button label="Copiar exportação" onClick={copyExport} />
          <Button label="Repor dados de exemplo" onClick={resetAll} />
        </div>
        <Text>{ioKind === "Documentos"
          ? "Formato: `tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem`, com tipo Recebimento ou Pagamento, datas DD/MM/AAAA e valores 1234,56. Aceita colagem direta do Excel (separado por tabulação)."
          : "Formato: `banco;saldo;ccc_disponivel`. Substituir remove os bancos que não estiverem na lista; os documentos desses bancos ficam sem banco."}</Text>
        <Grid columns={2}>
          <Section heading="Exportar (copiar para o Excel)">
            <textarea style={taStyle} readOnly value={exportText} />
          </Section>
          <Section heading="Importar (colar aqui)">
            <textarea style={taStyle} value={ioText} placeholder={ioKind === "Documentos" ? "Pagamento;FT 2026/123;Fornecedor X;30/11/2026;1500,00;Banco A;;Manual" : "Banco A;110000,00;500000"} onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setIoText(e.target.value)} />
            <div style={rowWrap}>
              <Button label="Acrescentar" variant="primary" onClick={() => doImport(false)} />
              <Button label="Substituir tudo" onClick={() => doImport(true)} />
            </div>
          </Section>
        </Grid>
        {ioMsg ? <Text>{ioMsg}</Text> : null}
        <Text>As alterações feitas nesta página não ficam gravadas ao fechar ou recarregar. Exporte para guardar o trabalho e cole de novo para continuar.</Text>
      </Section>

      <Section heading="Origem dos dados" id="origem" caption="Dados de exemplo sintéticos; substitua-os pelos seus através de «Importar e exportar».">
        <Rows rows={[
          { title: "Saldos iniciais", detail: "Exemplo fictício de saldo por banco à data de início e de CCC (conta corrente) disponível." },
          { title: "Recebimentos", detail: "Exemplo de previsão de recebimentos por cliente e banco, com data de vencimento." },
          { title: "Pagamentos a fornecedores", detail: "Exemplo de documentos a pagar sem banco atribuído (aparecem no grupo «Sem banco atribuído» até serem atribuídos) e alguns em atraso." },
          { title: "Financiamentos e outros pagamentos", detail: "Exemplo de pagamentos com banco e data, e de prestações mensais por contrato." },
          { title: "Não incluído", detail: "Os dados reais não fazem parte do repositório. Importe-os em runtime." },
        ]} />
      </Section>
    </Page>
  );
}
