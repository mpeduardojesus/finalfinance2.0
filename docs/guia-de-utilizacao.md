# Guia de utilização

> Os valores e nomes nos exemplos são **fictícios**.

## 1. Saldos e parâmetros

Na secção **Saldos bancários e parâmetros**:

- Edite **Banco**, **Saldo inicial (€)** e **CCC disponível (€)**. Aceita `1234,56`, `1.234,56` ou `1 234,56`. Se o valor for inválido aparece «Valor inválido» — **atenção:** o cálculo trata-o como 0 (ver [validação](validacao.md), V-05).
- **Adicionar banco** / **Remover** (os documentos do banco removido ficam «sem banco»).
- **Data de início**: `AAAA-MM-DD` (também aceita `DD/MM/AAAA`). É a data a que se referem os saldos. Se for inválida, a página usa a data por defeito e avisa.
- **Horizonte**: 4 a 12 semanas. **Vista**: Semanal (segunda a domingo) ou Diária.
- **Contar CCC como fundos disponíveis**: desligado = só o saldo conta; ligado = saldo + CCC.

## 2. Recebimentos e pagamentos

Nas secções **Recebimentos de clientes** e **Pagamentos a fornecedores e outros**:

- **Novo recebimento / Novo pagamento** abre uma linha de edição: documento, entidade, vencimento (`DD/MM/AAAA`), **data planeada** (opcional), valor (> 0) e banco. **Guardar** valida; **Cancelar** descarta.
- **Data planeada**: se preenchida, é a data usada no cálculo (a coluna mostra «(alterada)»). Em branco = usa o vencimento. Use-a para adiar/antecipar um pagamento e ver o efeito no plano.
- **Editar** / **Apagar** em cada linha. **Pesquisar** (documento, entidade ou origem) e filtro por **Banco**. Só as primeiras 40 linhas são mostradas, a menos que ative «Mostrar todas».
- Linhas **sem banco**: aparecem a amarelo e contam no grupo «Sem banco atribuído» (sem saldo, pelo que geram sempre «falta»). Use **Atribuir banco** + **Aplicar às N linhas sem banco do filtro** para atribuir em bloco (o filtro de pesquisa limita as linhas afetadas).

## 3. Ler o plano

- **Plano de pagamentos por banco**: o aviso no topo lista os bancos com falta de fundos (1.ª data e necessidade máxima). A tabela resume cada banco. Escolha o **Banco em detalhe** para ver os movimentos por ordem cronológica com o **saldo após** cada um; os pagamentos que deixam o saldo abaixo do limite aparecem como «Falta de fundos» (ou «Usa CCC»). Cada período termina com uma linha «Total».
- **Projeção de tesouraria**: gráficos e quadro por período (Saldo final / Recebimentos / Pagamentos / Líquido) e coluna «Bancos em falta» (avaliada ao **fecho** do período).
- Texto sob os parâmetros: nº de documentos fora do horizonte ou inválidos (não entram no cálculo).

## 4. Importar e exportar

Em **Importar e exportar** escolha *Documentos* ou *Saldos*.

- **Exportar**: copie o texto da caixa da esquerda («Copiar exportação») para o Excel ou para um ficheiro `.csv`.
- **Importar**: cole na caixa da direita e escolha **Acrescentar** (junta aos existentes) ou **Substituir tudo** (apaga primeiro — não há desfazer; exporte antes).
- A página **não grava** dados: ao fechar ou recarregar perde as alterações. Exporte para guardar o trabalho.

### Formato dos documentos

Colunas (cabeçalho opcional): `tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem`

```csv
tipo;documento;contraparte;vencimento;valor;banco;data_planeada;origem
Pagamento;FT 2026/123;Fornecedor X;30/11/2026;1500,00;Banco A;;Manual
Pagamento;FT 2026/124;"Fornecedor ""Y""; Lda";01/12/2026;2.500,50;Banco B;05/12/2026;Importado
Recebimento;FR 2026/9;Cliente Z;02/11/2026;12000,00;Banco A;;Previsão
Pagamento;FT 2026/125;Fornecedor W;15/12/2026;300,00;Sem banco;;
```

- `tipo`: começa por **R** (Recebimento) ou **P** (Pagamento).
- `vencimento` e `data_planeada`: `DD/MM/AAAA` (também `AAAA-MM-DD`, `DD-MM-AAAA`, `DD.MM.AAAA`). `data_planeada` pode ficar vazia.
- `valor`: positivo, vírgula decimal (`1234,56`, `1.234,56`). Zero ou inválido → linha ignorada. **Não use valores negativos** (são convertidos em positivos).
- `banco`: nome do banco (sem distinguir maiúsculas). Nome desconhecido **cria um banco novo com saldo 0**; vazio ou «Sem banco» = sem banco. Confirme a mensagem «bancos novos criados» para detetar gralhas.
- Colagem direta do Excel (separada por tabulação) funciona. O separador é detetado pela **1.ª linha**; não misture separadores.
- Não use `,` como separador de colunas quando o valor tem vírgula decimal (as colunas deslocam-se; ver V-04).
- Linhas ignoradas são indicadas como «linha N» (N conta só as linhas não vazias).

### Formato dos saldos

Colunas: `banco;saldo;ccc_disponivel`

```csv
banco;saldo;ccc_disponivel
Banco A;100000,00;500000,00
Banco B;20000,00;200000,00
Banco C;5000,00;0
```

- *Acrescentar*: atualiza os bancos existentes (por nome) e cria os novos.
- *Substituir*: remove os bancos não listados; os seus documentos ficam sem banco.
- **Cuidado:** um banco cujo nome comece por «Banco» na 1.ª linha é tomado por cabeçalho e ignorado (V-07). Inclua sempre a linha de cabeçalho `banco;saldo;ccc_disponivel`.

## 5. Fluxo recomendado

1. Importar saldos (com cabeçalho) e documentos (acrescentar).
2. Verificar a mensagem de importação (linhas ignoradas, bancos novos) e o texto de documentos inválidos/fora do horizonte.
3. Atribuir banco às linhas «sem banco».
4. Ler os avisos; ligar a CCC se for política usá-la.
5. Ajustar datas planeadas e rever o detalhe por banco.
6. Exportar documentos e saldos e guardar os ficheiros.
