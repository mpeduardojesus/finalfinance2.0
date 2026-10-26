# Relatório de validação

**Data:** 26/10/2026 (Europe/Lisbon) · **Objeto:** página «Plano de tesouraria» (`src/plano-tesouraria.canvas.tsx`, ~660 linhas) · **Método:** revisão de código completa + extração do cálculo para `src/tesouraria-core.ts` e 54 testes automáticos (ver [plano de testes](plano-de-testes.md)).

## Resumo

- O **núcleo de cálculo está correto** nos cenários testados: projeção por banco, CCC, em atraso, data planeada, semanas ISO/fronteiras, vista diária, grupo «Sem banco atribuído», exportação/importação normal.
- Foram encontrados **15 itens** (defeitos, riscos e limitações): 0 críticos, **6 de severidade média**, 8 baixa, 1 informativo. Os mais importantes: erros de vírgula flutuante que geram **falsas faltas de fundos** (V-01), **perda silenciosa de dados** em «Substituir tudo» (V-02), **importação errada em silêncio** (V-03, V-04) e **valores inválidos tratados como 0** (V-05).
- **Não foi possível executar a interface** (precisa do ambiente de Pages). Tudo o que é visual/estado React foi apenas lido, não testado.

## O que foi verificado (executado)

| Área | Evidência |
|---|---|
| Identidade da projeção (final = saldo + Σrec − Σpag), por banco | T10, T11 |
| Ordem dos eventos no dia (recebimentos antes de pagamentos) | T17 |
| Data planeada substitui vencimento; em atraso → dia de início | T12, T13, T29 |
| Semanas (segunda–domingo, ISO 53→1), fronteiras, fora do horizonte, vista diária | T06, T14–T16, T25, T30 |
| Falta, mínimo, necessidade máxima, 1.ª falta; CCC ligada/desligada; saldo inicial negativo | T18–T20 |
| Grupo sem banco; itens inválidos; dados vazios; parâmetros inválidos | T21–T24 |
| `parseNum`/`parseDate`/formatação | T01–T05, T07–T08 |
| CSV: separadores, aspas, vírgula decimal, datas, acrescentar/substituir, round-trip, saldos | T09, T31–T39 |
| Desempenho com 20 000 documentos | T40 |
| O núcleo testado é cópia fiel do `.tsx` | `tests/fidelity.mjs` (funções auxiliares e corpo da projeção idênticos; também confirmado por `diff` com o original) |

## O que NÃO foi verificado

- Renderização, layout e comportamento da UI (componentes `grok/canvas`, tabelas com campos de edição, foco ao escrever, gráficos `LineChart`/`ColumnSeries`, rótulos de eixo).
- Fluxos de estado React: adicionar/editar/cancelar/apagar, filtros, paginação 40/80, atribuição em bloco, remoção de banco durante edição (**analisados por leitura**, ver V-13).
- Área de transferência (`navigator.clipboard`), compatibilidade de browsers, formatação `pt-PT` noutros motores (só testada em Node 20 / ICU 76).
- Exatidão dos **dados de origem** (extração do Excel): não foi verificada contra o livro nesta validação; a documentação não reproduz valores reais.
- Acessibilidade, segurança (além de V-15) e utilização em dispositivos móveis.

## Defeitos e riscos

Severidade: **Média** = pode induzir uma decisão errada ou perder dados; **Baixa** = incómodo/risco raro; **Info** = limitação conhecida.

| ID | Sev. | Descrição | Evidência | Correção sugerida |
|---|---|---|---|---|
| V-01 | Média | O cálculo usa `number` em euros (vírgula flutuante). Somas como `0,30 − 0,10 − 0,20` dão −2,8e-17, que é `< 0`: marca **«Falta de fundos»** (necessidade 2,8e-17, mostrada como `-0,00 €`) quando o saldo fica exatamente a zero. | B01 | Calcular em **cêntimos inteiros** (`Math.round(v*100)`) ou comparar com tolerância (`< -0.005`); normalizar `-0,00`. |
| V-02 | Média | «Substituir tudo» com 0 linhas válidas (só cabeçalho, lixo, separador errado) **apaga todos os documentos** sem confirmação nem desfazer. Idem em Saldos: remove todos os bancos. | B02 | Se `novos.length === 0` abortar com mensagem; pedir confirmação; guardar cópia para «Desfazer». |
| V-03 | Média | Valores **negativos** na importação são convertidos em positivos (`Math.abs`): uma nota de crédito passa a pagamento/recebimento do mesmo tipo. (A edição manual rejeita negativos.) | B03 | Rejeitar a linha com mensagem, ou suportar tipo «Nota de crédito» que inverta o sinal. |
| V-04 | Média | Com `,` como separador (detetado quando a 1.ª linha não tem `;` nem tab) e vírgula decimal, as colunas deslocam-se: a linha é **aceite com valor errado** (`1234` em vez de `1234,56`) e cria um **banco fantasma** («56»). Sem erro. | B06 | Não aceitar `,` como separador (só `;`/tab) ou validar nº de colunas (8) e rejeitar. |
| V-05 | Média | Saldo/CCC inválido de um banco é usado como **0** no cálculo (só aparece a etiqueta «Valor inválido» na tabela de bancos); os alertas e totais continuam a ser apresentados como válidos. | T26 | Bloquear/avisar de forma global no plano («cálculo incompleto: saldo inválido em Banco X») ou não calcular esse banco. |
| V-06 | Média (negócio) | Recebimentos **em atraso** são assumidos recebidos no **dia de início**, e os do horizonte na data de vencimento (sem atraso médio de cobrança). Pode encobrir faltas reais. | B13 | Parâmetro «atraso médio de cobrança» e opção de excluir/realocar recebimentos em atraso. |
| V-07 | Baixa | Na importação de **saldos**, a 1.ª linha cujo nome comece por «Banco…» é tomada por cabeçalho e ignorada (ex.: «Banco Exemplo»). O próprio texto de ajuda da página começa por «Banco A;…». | B04 | Detetar cabeçalho pelo nome exato das colunas (`banco;saldo;…`) ou por o 2.º campo não ser numérico. |
| V-08 | Baixa | Sem deteção de duplicados: importar duas vezes duplica documentos (pagamentos a dobrar). | B05, T27 | Aviso de possíveis duplicados (tipo+documento+entidade+valor+vencimento) com opção de ignorar. |
| V-09 | Baixa | CSV: (a) separador só deduzido da 1.ª linha; (b) campos com quebra de linha entre aspas não são suportados; (c) «linha N» não corresponde à linha real quando há linhas em branco. | B07–B09 | Fazer *parse* do texto completo respeitando aspas; numerar pela linha original. |
| V-10 | Baixa | O quadro por período e «Bancos em falta» avaliam só o **fecho** do período; o aviso do plano usa o mínimo intra-período. Um banco pode aparecer «em falta» no resumo e não no quadro semanal. | B10 | Mostrar o mínimo do período (`min` por período) e usá-lo no alerta. |
| V-11 | Baixa | «Documentos fora do horizonte (X €)» soma recebimentos e pagamentos. | B11 | Mostrar separadamente R e P. |
| V-12 | Baixa | `parseNum`: `1.500` é lido como 1500 (pode ser 1,5); aceita `1e3`/`0x10`; o sinal `−` Unicode é rejeitado. `parseDate`: ano de 3 dígitos devolve ISO malformada; ISO `0050-…` vira 2050. | T03, B12 | Regex estrita para números; exigir 2 ou 4 dígitos no ano; aceitar `−`. |
| V-13 | Baixa | *(só por leitura de código, não executado)* (a) Com uma linha nova em edição, «Importar → Acrescentar» limpa o rascunho mas **deixa na lista a linha vazia com valor 0** (contada como «inválida»); (b) a exportação inclui essa linha em edição; (c) remover um banco enquanto se edita um documento desse banco deixa o rascunho a apontar para um id inexistente (tratado como «sem banco»). | M06, M08 | Remover o item provisório ao cancelar/importar/exportar; limpar rascunho ao remover banco. |
| V-14 | Info | Sem persistência; data de início por defeito fixa no código (`2026-10-26`), não «hoje»; sem ajuste de fins de semana/feriados; sem juros de CCC, câmbio ou transferências entre bancos; `eur()` depende do ICU do browser. | — | Ver [limitações](limitacoes-e-proximos-passos.md). |
| V-15 | Baixa | A exportação não neutraliza texto que comece por `=`, `+`, `-`, `@` (injeção de fórmulas ao abrir no Excel) — relevante se se importarem dados de terceiros. | B14 | Prefixar com `'` ou espaço nesses campos na exportação. |

## Confidencialidade

O código-fonte **original** da página **embutia dados reais** do livro de Excel da empresa (saldos e limites de CCC por banco, nomes de clientes e fornecedores com montantes, números de contratos de financiamento e a estrutura das folhas). Por isso:

- o repositório inclui uma **cópia saneada** (`src/plano-tesouraria.canvas.tsx`), em que apenas os arrays de dados iniciais (`SEED_BANKS`, `REC`, `ORIG`, `FORN`, `CTAS`, `FIN`) e os textos da secção «Origem dos dados» foram substituídos por dados **fictícios** (`Banco A…D`, `Cliente 01…`, `Fornecedor 01…`);
- a lógica (parse, projeção, importação, edição, interface) é **idêntica** à original — confirmado por `diff` das funções auxiliares e da projeção, e por `tests/fidelity.mjs`;
- os testes e a documentação usam apenas valores sintéticos;
- **o original com dados reais não deve ser commitado**; se o Page original continuar publicado, convém tratar o seu acesso como confidencial.

## Conclusão

O cálculo é fiável para o uso pretendido **depois de corrigir V-01** (cêntimos inteiros) e, antes de usar a importação com dados reais, **V-02, V-03 e V-04**. A camada de interface requer uma ronda de testes manuais (M01–M14).
