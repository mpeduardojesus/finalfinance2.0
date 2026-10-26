# Final Finance 2.0

Plano de tesouraria semanal/diário por banco: parte dos **saldos bancários** e das **datas de vencimento** de recebimentos e pagamentos, permite **alterações manuais** e produz um **plano de pagamentos por banco** com alertas de falta de fundos.

> Estado do repositório: protótipo funcional (uma página interativa) + núcleo de cálculo testado. Os dados incluídos são **fictícios**; os dados reais da empresa não fazem parte do repositório.

## Objetivo

Responder, para um horizonte de 4 a 12 semanas, às perguntas:

1. Com os saldos que tenho hoje em cada banco, e com os recebimentos e pagamentos previstos por data de vencimento, **em que banco e em que dia vai faltar dinheiro**?
2. **Quanto** é preciso cobrir (necessidade máxima) e **o que posso adiar** (data planeada manual) para não ficar a descoberto?
3. Qual é o **plano de pagamentos por banco**, documento a documento, com o saldo após cada movimento?

## Funcionalidades

- Saldos iniciais por banco e linha de **CCC** (conta corrente) disponível, editáveis; opção «contar CCC como fundos disponíveis».
- **Recebimentos** (clientes) e **pagamentos** (fornecedores, financiamentos, salários, outros) com documento, entidade, vencimento, valor, banco e origem.
- **Data planeada** manual por documento: substitui o vencimento no cálculo (o vencimento original é mantido).
- Adicionar, editar e apagar documentos; pesquisa e filtro por banco; atribuição em bloco de banco às linhas sem banco.
- Projeção **semanal** (semanas ISO, segunda a domingo) ou **diária**, horizonte de 4, 6, 8, 10 ou 12 semanas.
- Resumo por banco: saldo inicial, recebimentos, pagamentos, saldo final, saldo mínimo, 1.ª data de falta, necessidade máxima e estado (Coberto / Recorre à CCC / Falta de fundos).
- Detalhe do plano por banco: lista cronológica de movimentos com o **saldo após cada um** e marcação das linhas em que falta dinheiro.
- Grupo **«Sem banco atribuído»** para documentos sem banco (para não os perder no cálculo).
- Gráficos de saldo final e de recebimentos vs. pagamentos.
- **Importar/exportar** CSV (`;` ou tabulação, vírgula decimal, datas `DD/MM/AAAA`), com modos *Acrescentar* e *Substituir tudo*, para documentos e para saldos.

## Como funciona (resumo)

Para cada banco, os documentos são ordenados pela **data efetiva** (data planeada, se existir, senão vencimento; datas anteriores ao início contam como «em atraso» no dia de início). No mesmo dia os recebimentos entram antes dos pagamentos. O saldo corrente parte do saldo inicial e soma recebimentos / subtrai pagamentos; o **mínimo** atingido (com CCC, se ativa) determina a necessidade e a 1.ª falta. Detalhes em [`docs/arquitetura-e-calculo.md`](docs/arquitetura-e-calculo.md).

## Modelo de dados

| Entidade | Campos principais |
|---|---|
| Banco | `id`, `nome`, `saldo` (inicial, €), `ccc` (limite disponível, €) |
| Documento (`Item`) | `tipo` (R/P), `doc`, `parte`, `venc` (AAAA-MM-DD), `valor` (> 0), `banco`, `dataPlan`, `origem` |
| Parâmetros | data de início, horizonte (semanas), vista (semanal/diária), usar CCC |

## Como usar

Ver o [guia de utilização](docs/guia-de-utilizacao.md). Em resumo: (1) confirmar saldos e CCC; (2) importar ou editar recebimentos e pagamentos; (3) atribuir banco às linhas sem banco; (4) ler o plano por banco e ajustar datas planeadas; (5) exportar para guardar o trabalho — **a página não grava dados ao recarregar**.

## Limitações principais

Sem persistência; sem juros, câmbios, feriados nem dias úteis; recebimentos assumidos na data de vencimento; alguns defeitos de importação identificados na validação. Lista completa em [`docs/limitacoes-e-proximos-passos.md`](docs/limitacoes-e-proximos-passos.md) e [`docs/validacao.md`](docs/validacao.md).

## Estrutura do repositório

```
README.md                          este ficheiro
package.json                       scripts de teste (esbuild + node --test)
.gitignore
docs/
  requisitos.md                    requisitos funcionais e não funcionais
  arquitetura-e-calculo.md         modelo de dados, algoritmo de projeção, pressupostos
  guia-de-utilizacao.md            guia do utilizador e formatos CSV
  plano-de-testes.md               casos de teste e resultados
  validacao.md                     relatório de validação (verificado / não verificado / defeitos)
  limitacoes-e-proximos-passos.md  limitações e roadmap
src/
  plano-tesouraria.canvas.tsx      a página interativa (dados de exemplo sintéticos)
  tesouraria-core.ts               núcleo de cálculo extraído (cópia literal das funções do .tsx)
tests/
  core.test.ts                     54 testes do núcleo (dados sintéticos)
  fidelity.mjs                     verifica que o núcleo é cópia fiel do .tsx
```

## Executar os testes

```bash
npm install
npm test        # 54 testes + verificação de fidelidade
```

Requer Node.js ≥ 20. A página `.tsx` importa de `grok/canvas` (ambiente de «Pages» do Grok Bot) e **não corre isolada** fora desse ambiente; por isso o cálculo foi extraído para `src/tesouraria-core.ts`, que é o que os testes executam.

## Dados e confidencialidade

O `.tsx` incluído é uma **cópia saneada**: os dados iniciais reais (saldos, clientes, fornecedores, contratos) foram substituídos por dados fictícios. **Não coloque dados reais neste repositório**; importe-os em runtime através de «Importar e exportar».
