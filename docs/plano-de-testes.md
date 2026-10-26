# Plano de testes

Execução: `npm test` (esbuild + `node --test`), Node 20.19, em 26/10/2026 (Europe/Lisbon). **Resultado: 54/54 testes passam; verificação de fidelidade OK.** Todos os dados de teste são sintéticos.

Os testes **T** verificam comportamento correto. Os testes **B** são de *caracterização*: afirmam o comportamento **defeituoso atual** (documentado em [validacao.md](validacao.md)); passam hoje e **devem falhar quando o defeito for corrigido** (então atualizar o teste para o comportamento correto).

## Âmbito

| Camada | Testado | Como |
|---|---|---|
| Funções puras (parse, formatação, datas, semanas ISO) | Sim | `tests/core.test.ts` |
| Projeção por banco (CCC, atraso, buckets, falta, sem banco) | Sim | idem, via `project()` |
| Importação/exportação CSV (parte pura) | Sim | `importDocs`, `importBanks`, `exportDocs`, `exportBanks` |
| Fidelidade núcleo ↔ `.tsx` | Sim | `tests/fidelity.mjs` (helpers e projeção idênticos linha a linha) |
| Interface (React/Pages), gráficos, estado, filtros, área de transferência | **Não** | exigem o ambiente de Pages; ver «Testes manuais pendentes» |

## Testes automáticos — corretos (T)

| ID | O que verifica | Resultado |
|---|---|---|
| T01 | `parseNum` com `1234,56`, `1.234,56`, `1,234.56`, espaços, NBSP, `€`, negativos e parênteses | Passa |
| T02 | `parseNum` devolve `null` para vazio, texto, `1,2,3`, `1.2.3`, `--5`, `Infinity`, sinal `−` Unicode | Passa |
| T03 | `parseNum`: `1.500`→1500 (ambíguo); aceita `1e3` e `0x10` (risco R-07) | Passa (comportamento documentado) |
| T04 | `parseDate` aceita ISO, `DD/MM/AAAA`, `-`, `.`, ano de 2 dígitos, 29/02 bissexto | Passa |
| T05 | `parseDate` rejeita 31/04, 29/02 não bissexto, mês/dia fora de limites, `MM/DD` | Passa |
| T06 | Segunda-feira da semana e semanas ISO (44/2026; 53/2026; 1/2025; 53/2020 …) | Passa |
| T07 | `fmtD`, `dm` | Passa |
| T08 | Formatação `eur` pt-PT (`1234,50 €`; `12 345,50 €`) | Passa |
| T09 | `splitLine` (aspas, `""`, `;` dentro de aspas), `csvQ`, `detectDelim` | Passa |
| T10 | Projeção base: saldo + recebimentos − pagamentos por semana | Passa |
| T11 | Identidade `final = saldo + Σrec − Σpag` | Passa |
| T12 | Data planeada substitui vencimento | Passa |
| T13 | Em atraso → 1.º período, no dia de início | Passa |
| T14 | Fronteiras de semana (domingo/segunda), fora do horizonte (contagem e valor) | Passa |
| T15 | Semana começa na segunda mesmo com início a meio da semana | Passa |
| T16 | Vista diária (28 períodos, rótulos, saldos) | Passa |
| T17 | Mesmo dia: recebimentos antes de pagamentos (sem falta falsa) | Passa |
| T18 | Falta: mínimo, necessidade máxima, 1.ª falta, saldos após | Passa |
| T19 | CCC desligada/ligada; falta só acima do limite | Passa |
| T20 | Saldo inicial negativo → 1.ª falta = data de início | Passa |
| T21 | Grupo «Sem banco atribuído» (banco vazio ou inexistente; só existe se houver itens) | Passa |
| T22 | Valor ≤ 0 ou data inválida → excluído e contado | Passa |
| T23 | Sem bancos nem documentos | Passa |
| T24 | Início inválido → data por defeito; horizonte inválido → 6 | Passa |
| T25 | Horizontes 4/6/8/10/12, semanal e diária | Passa |
| T26 | Saldo/CCC inválido ou vazio tratado como 0 | Passa |
| T27 | Duplicados exatos somam (sem deduplicação) | Passa |
| T28 | Bancos independentes | Passa |
| T29 | Itens anteriores à 1.ª segunda-feira contam em atraso | Passa |
| T30 | Transição de ano nas semanas (S53→S1) | Passa |
| T31 | Importar documentos (`;`, cabeçalho, vírgula decimal, datas, data planeada) | Passa |
| T32 | Importar com tabulação (colagem do Excel) | Passa |
| T33 | Linhas inválidas ignoradas e reportadas | Passa |
| T34 | Banco desconhecido cria banco; «Sem banco»/vazio → sem banco | Passa |
| T35 | Acrescentar vs. substituir | Passa |
| T36 | Exportar → importar (round-trip) com aspas e `;` nos campos | Passa |
| T37 | Importar saldos (acrescentar, nomes sem distinguir maiúsculas) | Passa |
| T38 | Importar saldos (substituir): documentos de bancos removidos ficam sem banco; ids preservados | Passa |
| T39 | Exportar saldos | Passa |
| T40 | Desempenho: 20 000 documentos, diária 12 semanas < 2 s | Passa (suite completa em ≈ 0,25 s) |

## Testes automáticos — defeitos caracterizados (B)

| ID | Defeito (ver validacao.md) | Sev. | Teste passa porque… |
|---|---|---|---|
| B01 | V-01 | Média | `0,30 − 0,10 − 0,20` dá −2,8e-17 → falsa «Falta de fundos», mostrada `-0,00 €` |
| B02 | V-02 | Média | «Substituir tudo» com 0 linhas válidas apaga todos os documentos |
| B03 | V-03 | Média | `-100,00` importado como `100` |
| B04 | V-07 | Baixa | `Banco Exemplo;…` na 1.ª linha é ignorado como cabeçalho |
| B05 | V-08 | Baixa | duplicados duplicam ao importar duas vezes |
| B06 | V-04 | Média | separador `,` + vírgula decimal: colunas deslocadas, linha importada com valor errado, cria banco «56» |
| B07 | V-09 | Baixa | separador detetado só na 1.ª linha |
| B08 | V-09 | Baixa | campo com quebra de linha entre aspas parte o round-trip |
| B09 | V-09 | Baixa | «linha N» conta só linhas não vazias |
| B10 | V-10 | Baixa | «Bancos em falta» semanal ignora défice intra-semana |
| B11 | V-11 | Baixa | «fora do horizonte» soma R e P |
| B12 | V-12 | Baixa | ano de 3 dígitos → data ISO malformada; ISO com ano <100 → 20xx |
| B13 | V-06 | Média | recebimento em atraso assumido no dia de início encobre falta |
| B14 | V-15 | Baixa | exportação não neutraliza fórmulas (`=1+1`) |

## Testes manuais pendentes (não executados)

Estes casos exigem abrir a página; **não foram executados** nesta validação:

| ID | Caso | Resultado esperado |
|---|---|---|
| M01 | Carregar a página sem erros; ver os 4 bancos de exemplo | Sem erro de consola; tabelas preenchidas |
| M02 | Novo pagamento → Guardar com data inválida / valor 0 / valor negativo | Mensagem de erro; nada gravado |
| M03 | Novo pagamento válido → aparece no topo e altera o plano | Plano recalculado |
| M04 | Editar documento e definir data planeada → «(alterada)» e movimento muda de período | idem |
| M05 | Apagar documento (também o que está em edição) | Linha removida; edição fechada |
| M06 | Carregar «Novo pagamento» e depois importar «Acrescentar» | Verificar se fica uma linha vazia com valor 0 (suspeita V-13) |
| M07 | Atribuir banco em bloco com filtro de pesquisa ativo | Só as linhas filtradas sem banco mudam |
| M08 | Remover banco com documentos; remover banco enquanto se edita um documento desse banco | Documentos ficam sem banco; sem erro |
| M09 | Mostrar todas / limite de 40 e 80 linhas | Contagens corretas |
| M10 | Gráficos com valores negativos, 12 semanas diárias (84 pontos), rótulos do eixo | Legíveis, linha do zero |
| M11 | «Copiar exportação» (permissões da área de transferência) | Mensagem de sucesso ou de recurso manual |
| M12 | Importar texto copiado do Excel (tabulação) | Linhas importadas |
| M13 | Alterar data de início para fim de semana / meio da semana | 1.ª semana e rótulos corretos |
| M14 | «Repor dados» | Estado inicial restaurado |

## Como adicionar testes

Acrescente casos a `tests/core.test.ts` usando os auxiliares `bk()` (banco), `it()` (documento) e `P()` (parâmetros). Funções novas no `.tsx` devem ser copiadas literalmente para `src/tesouraria-core.ts` e cobertas pelo `fidelity.mjs`.
