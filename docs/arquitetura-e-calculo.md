# Arquitetura e cálculo

## Visão geral

Uma única página React (`src/plano-tesouraria.canvas.tsx`, componentes `grok/canvas`) com estado em memória (`useState`). O cálculo é feito num `useMemo` que depende de bancos, documentos e parâmetros. As mesmas funções puras foram copiadas para `src/tesouraria-core.ts` para serem testadas; `tests/fidelity.mjs` confirma que cada função auxiliar e cada linha da projeção existem literalmente no `.tsx`.

```
Estado (bancos, documentos, parâmetros)
        │
        ▼
  project(): períodos → data efetiva → agrupar por banco → ordenar → saldo corrente
        │
        ├─► resumo por banco (saldo final, mínimo, 1.ª falta, necessidade)
        ├─► detalhe por banco (movimentos + saldo após)
        └─► quadro/gráficos por período (recebimentos, pagamentos, saldo final)
```

## Modelo de dados

```ts
interface Bank { id: string; nome: string; saldo: string; ccc: string }   // saldo e ccc em texto (PT), convertidos com parseNum
interface Item { id: string; tipo: "R" | "P"; doc: string; parte: string;
                 venc: string;       // AAAA-MM-DD
                 valor: number;      // > 0, em euros (arredondado a 2 casas na edição/importação)
                 banco: string;      // id do banco ou "" (= sem banco)
                 dataPlan: string;   // "" ou AAAA-MM-DD (substitui venc)
                 origem: string }
```

Notas: o sinal do documento vem do `tipo` (R soma, P subtrai); `valor` tem de ser > 0 para entrar no cálculo. Datas são tratadas como dias inteiros em UTC (sem horas nem fusos).

## Algoritmo de projeção

Entradas: `inicio`, `horiz` (semanas), `vista` (Semanal|Diária), `useCCC`.

1. **Data de início** `startN` = `inicio` interpretada (se inválida, usa a data por defeito `2026-10-26`). Horizonte `nW = Number(horiz) || 6`.
2. **Períodos**
   - *Semanal*: `nW` semanas ISO consecutivas a começar na **segunda-feira** da semana de `startN` (rótulo `Sem. NN · dd/mm–dd/mm`). Se o início não for segunda-feira, a 1.ª semana é inteira mas o saldo inicial vale a partir de `startN`.
   - *Diária*: `nW × 7` dias a partir de `startN`.
3. **Índice do período** de uma data: semanal `floor((n − segunda0)/7)`; diária `n − startN`. Índice negativo → 0 (**em atraso**); índice ≥ nº de períodos → **fora do horizonte** (excluído e contado em `fora`).
4. **Data efetiva** de cada documento: `dataPlan` válida, senão `venc`. Sem data válida ou com `valor ≤ 0` → inválido (excluído, contado em `invalid`).
5. **Banco**: se `banco` não existe na lista (inclui `""`), vai para o grupo `__sem` («Sem banco atribuído», saldo 0, sem CCC); o grupo só existe se tiver documentos.
6. **Ordenação** por banco: `eff = max(data, startN)` ascendente; no mesmo dia **recebimentos antes de pagamentos**; depois valor decrescente.
7. **Saldo corrente**: `run = saldo inicial`; para cada movimento `run ± valor`; `saldo após` = `run`; `min` = menor `run` (inclui o saldo inicial).
8. **Limite** `lim = useCCC ? ccc : 0`. **Falta** quando `run + lim < 0`.
   - `first` = `eff` do 1.º movimento em falta (ou `startN` se o saldo inicial + lim já for negativo);
   - `need = max(0, −(min + lim))` — necessidade máxima de fundos;
   - Estado: `need > 0` → Falta de fundos; senão `min < 0` → Recorre à CCC; senão Coberto.
9. **Totais por período**: `rec[i]`, `pag[i]` por soma; `close[i]` = saldo inicial + Σ(rec − pag) até ao período *i* (fecho do período).
10. **Totais da tabela** somam todos os bancos, incluindo «Sem banco atribuído».

### Exemplo (valores fictícios)

Banco A com saldo 1000,00; recebimento 500,00 a 28/10; pagamento 300,00 a 29/10; pagamento 1000,00 a 03/11. Semana 44 fecha em 1200,00; semana 45 fecha em 200,00; mínimo 200,00; sem falta (teste T10).

## Pressupostos

- Recebimentos e pagamentos ocorrem **na data efetiva**, integralmente e no banco indicado.
- O saldo inicial é o saldo do banco no **início** da data de início (movimentos desse dia ainda contam).
- Documentos em atraso são **liquidados no dia de início** (inclusive recebimentos — pressuposto otimista).
- Não há juros da CCC, comissões, câmbio, feriados, fins de semana ajustados nem transferências entre bancos.
- A CCC é um limite fixo de descoberto por banco; não é modelado o custo nem o reembolso.

## Importação / exportação

- Separador deduzido da **1.ª linha**: tabulação, senão `;`, senão `,`. Campos entre aspas `"..."` com `""` para aspas.
- **Documentos** (`tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem`): tipo começa por `r`/`p`; valor aceita `1234,56`, `1.234,56`, `1,234.56`, `€`, parênteses para negativo (o módulo é usado); banco procurado pelo nome (sem distinguir maiúsculas), criado com saldo 0 se não existir; `Sem banco`/vazio = sem banco.
- **Saldos** (`banco;saldo;ccc_disponivel`): atualiza por nome ou cria; em *Substituir*, bancos ausentes são removidos e os seus documentos ficam sem banco.
- Exportação usa `;`, vírgula decimal sem separador de milhares, datas `DD/MM/AAAA`.

## Formatação

`eur()` usa `toLocaleString("pt-PT")` (em Node 20/ICU 76: `1234,50 €` e `12 345,50 €` — o pt-PT não agrupa números de 4 dígitos). `eurK()` mostra milhares arredondados (`k€`).
