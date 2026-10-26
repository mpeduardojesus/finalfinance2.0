// Núcleo de cálculo do Plano de tesouraria, extraído (cópia literal) de src/plano-tesouraria.canvas.tsx
// para poder ser testado fora do ambiente de Pages. Não contém dados reais.
// A fidelidade face ao ficheiro .tsx é verificada por tests/fidelity.mjs.

export type Tipo = "R" | "P";
export interface Bank { id: string; nome: string; saldo: string; ccc: string }
export interface Item { id: string; tipo: Tipo; doc: string; parte: string; venc: string; valor: number; banco: string; dataPlan: string; origem: string }
export interface Period { label: string; short: string }
export interface Ev { n: number; eff: number; atraso: boolean; p: number; item: Item; saldo: number }

export const SEM = "__sem";
export const DEFAULT_START = "2026-10-26";

export function fmtIn(n: number): string { return n.toFixed(2).replace(".", ","); }
let uidCounter = 0;
export function uid(): string { uidCounter += 1; return "u" + Date.now().toString(36) + uidCounter; }

export function parseNum(s: string): number | null {
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
export function pad2(n: number): string { return n < 10 ? "0" + n : String(n); }
export function validYMD(y: number, m: number, d: number): string | null {
  if (y < 100) y += 2000;
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
  return y + "-" + pad2(m) + "-" + pad2(d);
}
export function parseDate(s: string): string | null {
  const t = s.trim();
  let m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t);
  if (m) return validYMD(Number(m[1]), Number(m[2]), Number(m[3]));
  m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/.exec(t);
  if (m) return validYMD(Number(m[3]), Number(m[2]), Number(m[1]));
  return null;
}
export function dayNum(iso: string): number {
  const y = Number(iso.slice(0, 4)), m = Number(iso.slice(5, 7)), d = Number(iso.slice(8, 10));
  return Math.round(Date.UTC(y, m - 1, d) / 86400000);
}
export function isoOf(n: number): string { return new Date(n * 86400000).toISOString().slice(0, 10); }
export function fmtD(iso: string): string { return iso.length === 10 ? iso.slice(8, 10) + "/" + iso.slice(5, 7) + "/" + iso.slice(0, 4) : iso; }
export function dm(n: number): string { const s = isoOf(n); return s.slice(8, 10) + "/" + s.slice(5, 7); }
export function mondayOf(n: number): number { return n - ((n + 3) % 7); }
export function isoWeek(n: number): number {
  const thu = mondayOf(n) + 3;
  const y = new Date(thu * 86400000).getUTCFullYear();
  const jan1 = Math.round(Date.UTC(y, 0, 1) / 86400000);
  return Math.floor((thu - jan1) / 7) + 1;
}
const WD = ["seg", "ter", "qua", "qui", "sex", "sáb", "dom"];
export function eur(n: number): string {
  return n.toLocaleString("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " €";
}
export function eurK(n: number): string {
  return (n / 1000).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " k€";
}
export function sum(a: number[]): number { let s = 0; for (const v of a) s += v; return s; }
export function csvQ(s: string): string { return /[;"\n\t]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
export function splitLine(line: string, delim: string): string[] {
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
export function detectDelim(line: string): string { return line.indexOf("\t") >= 0 ? "\t" : line.indexOf(";") >= 0 ? ";" : ","; }

export interface Params { inicio: string; horiz: string; vista: string; useCCC: boolean }
export function project(banks: Bank[], items: Item[], prm: Params) {
  const { inicio, horiz, vista, useCCC } = prm;
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
}

// ---- Importação (parte pura de doImport; os setState foram substituídos por valores devolvidos) ----
export function importBanks(ioText: string, banks: Bank[], items: Item[], replace: boolean) {
  const lines = ioText.split(/\r?\n/).filter((l) => l.trim() !== "");
  const delim = detectDelim(lines[0] ?? "");
  const errors: string[] = [];
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
  const items2 = items.map((x) => x.banco && !ids.has(x.banco) ? { ...x, banco: "" } : x);
  return { banks: next, items: items2, n, errors };
}

export function importDocs(ioText: string, banks: Bank[], items: Item[], replace: boolean) {
  const lines = ioText.split(/\r?\n/).filter((l) => l.trim() !== "");
  const delim = detectDelim(lines[0] ?? "");
  const errors: string[] = [];
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
  return { banks: nb, items: replace ? novos : items.concat(novos), novos, errors };
}

// ---- Exportação (cópia de exportDocs/exportBanks) ----
export function exportDocs(items: Item[], banks: Bank[]): string {
  const bankIds = new Set(banks.map((b) => b.id));
  const bankName = (id: string): string => { const b = banks.find((x) => x.id === id); return b ? b.nome : ""; };
  return ["tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem"].concat(items.map((it) =>
    [it.tipo === "R" ? "Recebimento" : "Pagamento", it.doc, it.parte, fmtD(it.venc), fmtIn(it.valor), bankIds.has(it.banco) ? bankName(it.banco) : "", it.dataPlan ? fmtD(it.dataPlan) : "", it.origem].map(csvQ).join(";"))).join("\n");
}
export function exportBanks(banks: Bank[]): string {
  return ["banco;saldo;ccc_disponivel"].concat(banks.map((b) => [b.nome, fmtIn(parseNum(b.saldo) ?? 0), fmtIn(parseNum(b.ccc) ?? 0)].map(csvQ).join(";"))).join("\n");
}
