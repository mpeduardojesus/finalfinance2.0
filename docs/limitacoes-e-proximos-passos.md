# Limitações e próximos passos

## Limitações atuais

**Produto**
- **Sem persistência**: o estado vive em memória; recarregar a página perde as alterações (exportar/colar manualmente).
- **Sem dias úteis nem feriados**: vencimentos ao fim de semana ficam nesse dia.
- **Sem juros/comissões da CCC, câmbio, nem transferências entre bancos**; a CCC é um limite fixo por banco.
- **Recebimentos pontuais e certos**: sem atraso médio de cobrança, probabilidade ou parcelamento.
- Os documentos têm **um único banco**; não há divisão de um pagamento por vários bancos.
- Data de início por defeito fixa no código; horizontes limitados a 4–12 semanas.
- O grupo «Sem banco atribuído» não tem saldo, pelo que gera sempre «falta» até os documentos serem atribuídos (intencional).

**Técnicas**
- A página depende do ambiente de Pages (`grok/canvas`); não corre isolada. O núcleo de cálculo foi duplicado em `src/tesouraria-core.ts` (cópia literal verificada por `fidelity.mjs`) em vez de ser importado pela página.
- Valores monetários em vírgula flutuante (V-01).
- Camada de interface sem testes automáticos.

**Defeitos conhecidos**: ver V-01 a V-15 em [validacao.md](validacao.md).

## Próximos passos (por prioridade)

1. **Corrigir V-01** (cêntimos inteiros) e atualizar B01 → teste de comportamento correto.
2. **Endurecer a importação** (V-02, V-03, V-04, V-07, V-09): validar nº de colunas, não aceitar `,` como separador, confirmar «Substituir», rejeitar negativos, cabeçalho por nome, pré-visualização com resumo (linhas válidas/inválidas, bancos novos) antes de aplicar.
3. **Avisos globais de qualidade de dados** (V-05, V-11, V-13): saldos inválidos, documentos sem banco, inválidos e fora do horizonte num painel único.
4. **Persistência**: guardar/carregar (JSON ou CSV) e histórico de cenários (comparar «base» vs. «com datas planeadas»).
5. **Alerta por mínimo do período** no quadro semanal (V-10).
6. **Regras de negócio**: atraso médio de cobrança, ajuste a dias úteis/feriados, custo da CCC, transferências entre bancos, câmbio.
7. **Testes de interface** (M01–M14) e extração do núcleo para um módulo partilhado entre a página e os testes, eliminando a duplicação.
8. **Ligação ao ERP/Excel** para evitar a introdução manual (leitura direta de saldos e documentos em aberto).
9. Dados reais **fora do repositório** (pasta ignorada, variáveis de ambiente ou importação em runtime).
