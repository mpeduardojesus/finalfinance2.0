# Requisitos

Origem: pedido do utilizador — *«plano de tesouraria baseado nos saldos das contas bancárias, mais um plano de recebimentos e pagamentos baseado nas datas de vencimento dos documentos, com inputs manuais; o resultado é o plano de pagamentos por banco»*. Os requisitos abaixo são a interpretação desse pedido; os marcados **[inferido]** não foram pedidos literalmente.

## Requisitos funcionais

| ID | Requisito | Prioridade | Estado* |
|---|---|---|---|
| RF-01 | Registar saldo inicial por banco, editável. | Must | Implementado; cálculo verificado (T10, T26) |
| RF-02 | Registar linha CCC disponível por banco e permitir incluí-la ou não nos fundos disponíveis. | Must [inferido] | Implementado; verificado (T19) |
| RF-03 | Manter plano de recebimentos com data de vencimento, valor, cliente e banco. | Must | Implementado; cálculo verificado |
| RF-04 | Manter plano de pagamentos com data de vencimento, valor, fornecedor e banco. | Must | Implementado; cálculo verificado |
| RF-05 | Permitir **inputs manuais**: adicionar, editar e apagar documentos; alterar saldos; alterar a data de início. | Must | Implementado; UI **não** testada em execução |
| RF-06 | Permitir **data planeada** diferente do vencimento, usada no cálculo. | Must [inferido] | Verificado (T12) |
| RF-07 | Calcular a projeção do saldo por banco: saldo inicial + recebimentos − pagamentos pela data efetiva. | Must | Verificado (T10, T11, T17) |
| RF-08 | Mostrar o **plano de pagamentos por banco** (movimentos ordenados com saldo após cada um). | Must | Implementado; lógica verificada, apresentação não testada |
| RF-09 | Sinalizar falta de fundos (1.ª data, necessidade máxima, linhas em falta). | Must | Verificado (T18, T19, T20); defeito V-01 |
| RF-10 | Agrupar documentos sem banco num grupo «Sem banco atribuído» e permitir atribuí-los (individual e em bloco). | Should | Cálculo verificado (T21); atribuição em bloco **não** testada |
| RF-11 | Vistas semanal e diária; horizonte 4–12 semanas. | Should | Verificado (T14–T16, T25, T30) |
| RF-12 | Tratar documentos em atraso (vencimento anterior ao início). | Should | Verificado (T13, T29) |
| RF-13 | Importar/exportar documentos e saldos em CSV (`;`/tab, vírgula decimal, `DD/MM/AAAA`), acrescentando ou substituindo. | Should | Verificado, com defeitos V-02…V-09 |
| RF-14 | Filtrar e pesquisar documentos por banco e texto. | Could | Implementado; não testado |
| RF-15 | Gráficos de saldo e de recebimentos/pagamentos. | Could | Implementado; não testado |
| RF-16 | Gravar o trabalho entre sessões. | Should [inferido] | **Não implementado** (só exportação manual) |

\* «Verificado» = coberto por teste automático do núcleo de cálculo (ver [plano de testes](plano-de-testes.md)). Nada da camada visual (React/Pages) foi executado.

## Requisitos não funcionais

| ID | Requisito | Estado |
|---|---|---|
| RNF-01 | Idioma da interface: português europeu; moeda EUR; números `1234,56`; datas `DD/MM/AAAA`. | Implementado; formatação testada em Node (T08) |
| RNF-02 | Cálculo determinístico e sem dependências externas (funções puras). | Cumprido |
| RNF-03 | Desempenho: recalcular em tempo útil com milhares de documentos. | Verificado: 20 000 documentos, vista diária 12 semanas em < 2 s (T40) |
| RNF-04 | Exatidão monetária: sem erros de arredondamento visíveis. | **Não cumprido** — usa vírgula flutuante (V-01) |
| RNF-05 | Confidencialidade: dados reais não devem ficar no repositório. | Cumprido na cópia saneada; o original embutia dados reais (ver [validação](validacao.md)) |
| RNF-06 | Robustez a entradas inválidas (datas/valores) com mensagens claras. | Parcial: edição manual valida; importação ignora linhas inválidas mas há casos silenciosos (V-02, V-04) |
| RNF-07 | Testabilidade: lógica separada da UI. | Parcial: lógica estava embutida na página; foi extraída para `src/tesouraria-core.ts` |
| RNF-08 | Persistência/segurança dos dados entre sessões. | Não cumprido (RF-16) |
