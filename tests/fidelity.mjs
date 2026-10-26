// Verifica que o núcleo testado (src/tesouraria-core.ts) é cópia fiel do código do Page (src/plano-tesouraria.canvas.tsx):
// cada função auxiliar e cada linha (aparada) do corpo da projeção têm de existir literalmente no .tsx.
import { readFileSync } from "node:fs";
const tsx = readFileSync(new URL("../src/plano-tesouraria.canvas.tsx", import.meta.url), "utf8");
const core = readFileSync(new URL("../src/tesouraria-core.ts", import.meta.url), "utf8");
const tsxLines = new Set(tsx.split("\n").map((l) => l.trim()));
const helpers = ["fmtIn","parseNum","pad2","validYMD","parseDate","dayNum","isoOf","fmtD","dm","mondayOf","isoWeek","eur","eurK","sum","csvQ","splitLine","detectDelim"];
let bad = 0;
const coreLines = core.split("\n");
// funções auxiliares: bloco (até à linha "}" ou linha única) tem de existir no tsx
for (const name of helpers) {
  const i = coreLines.findIndex((l) => l.startsWith("export function " + name + "("));
  if (i < 0) { console.error("em falta no core:", name); bad++; continue; }
  const blk = [coreLines[i].replace("export function", "function")];
  if (!/\}\s*$/.test(coreLines[i]) || coreLines[i].trim().endsWith("{")) { for (let j = i + 1; j < coreLines.length; j++) { blk.push(coreLines[j]); if (coreLines[j] === "}") break; } }
  for (const l of blk) if (l.trim() && !tsxLines.has(l.trim())) { console.error("linha diferente em", name, "->", l.trim()); bad++; }
}
// corpo da projeção
const a = coreLines.findIndex((l) => l.startsWith("export function project("));
const b = coreLines.findIndex((l, i) => i > a && l === "}");
for (const l of coreLines.slice(a + 2, b)) if (l.trim() && !tsxLines.has(l.trim())) { console.error("linha da projeção ausente no .tsx ->", l.trim()); bad++; }
// funções de importação: linhas de lógica (excluindo as adaptadas)
const adapted = /^(export function|const lines|const delim|const errors|const next|const nb|const items2|return \{|\}|let n|const ids|const bankIds|const bankName|const resolve|lines\.forEach|const novos|const c =|\/\/)/;
const imp = coreLines.slice(coreLines.findIndex((l) => l.startsWith("export function importBanks")));
let checked = 0, notFound = 0;
for (const l of imp) { const t = l.trim(); if (!t || adapted.test(t)) continue; checked++; const t2 = t.replace(/^return /, ""); if (!tsxLines.has(t) && !tsx.includes(t2)) { notFound++; console.error("(aviso) linha de importação/exportação não literal:", t.slice(0, 110)); } }
console.log(`fidelidade: helpers=${helpers.length}, linhas de import/export verificadas=${checked}, divergentes=${notFound}`);
if (bad) { console.error("FALHOU: " + bad + " divergências"); process.exit(1); }
console.log("fidelidade OK (helpers e projeção idênticos ao .tsx)");
