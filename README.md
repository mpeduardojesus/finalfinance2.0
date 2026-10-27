# Microplasticos Intranet

Página inicial da intranet (estilo AdminLTE 2) com **PHP 8.3**, **API REST em JSON** e **Oracle Database 19c** (extensão `oci8`), tudo em Docker.

- Frontend: AdminLTE 2.4 + Bootstrap 3 + Font Awesome 4 (via CDN). Logótipo, avatar e banner da "Missão Social" são placeholders em CSS/SVG.
- Backend: PHP puro (`strict_types`, sem framework), router próprio em `src/Router.php`.
- Dados: **Oracle 19c** quando as variáveis `ORACLE_*` estão definidas; caso contrário usa ficheiros JSON em `data/` (modo demo).

## Estrutura

```
Dockerfile              php:8.3-apache + Oracle Instant Client + oci8
docker-compose.yml      serviço web em http://localhost:8080
.env.example            variáveis de ligação ao Oracle
router.php              router para `php -S` (sem Docker)
public/                 DocumentRoot (index.php = front controller, .htaccess, assets/)
src/                    Router, Api, Validator, Database (oci8), Repository/{DataStore,OracleStore,JsonStore}
views/home.html         página inicial
data/                   JSON do modo demo + menu.json
data/sample/            dados FICTÍCIOS do Plano de tesouraria (demonstração)
data/private/           dados CONFIDENCIAIS do Plano de tesouraria (ignorado pelo git e pelo Docker)
db/schema.sql           DDL + dados de exemplo para Oracle 19c
src/Treasury/           Plano de tesouraria: Auth, Storage, SageImporter, TreasuryService, TreasuryApi, Util
views/treasury.html     página /treasury (JS em public/assets/js/treasury.js)
composer.json           PhpSpreadsheet (importação do .xls do Sage)
tests/ tools/           auto-teste da lógica e gerador dos dados fictícios
```

## Executar (modo demo, sem Oracle)

Requisitos: Docker + Docker Compose.

```bash
docker compose up --build
```

Abrir <http://localhost:8080>. Sem `ORACLE_*` definidas, a aplicação usa `data/*.json` (`GET /api/health` mostra `"backend":"json"`).

> O primeiro build descarrega o **Oracle Instant Client (Basic + SDK)** diretamente da Oracle (`download.oracle.com`) e compila a extensão `oci8` via PECL — precisa de acesso à Internet e demora alguns minutos. O Instant Client **não** está incluído neste projeto; aceite os termos de licença da Oracle antes de o usar. A imagem usa a arquitetura `x64`; em Mac Apple Silicon/ARM64 use `--platform linux/amd64` ou ajuste os URLs no `Dockerfile` para `linux-arm64`.

## Ligar ao Oracle 19c real

1. Criar o esquema e os dados de exemplo (como o utilizador da aplicação):

   ```bash
   sqlplus APP_USER/PASSWORD@//HOST:1521/SERVICO @db/schema.sql
   ```

   O `db/schema.sql` cria as tabelas `COMMUNICATIONS` e `FACTS` com colunas `IDENTITY` (19c) e insere os registos de exemplo.

2. Copiar `.env.example` para `.env` e preencher (o `.env` está no `.gitignore`; **não** colocar credenciais no código):

   ```env
   ORACLE_HOST=oracle19c.empresa.local
   ORACLE_PORT=1521
   ORACLE_SERVICE=ORCLPDB1        # service name (não SID)
   ORACLE_USER=intranet
   ORACLE_PASSWORD=********
   ORACLE_CHARSET=AL32UTF8
   ```

3. Arrancar: `docker compose up --build`. Verificar: `curl http://localhost:8080/api/health` → `"backend":"oracle"`.

Notas:
- O contentor tem de conseguir chegar ao host Oracle (rede/firewall). Se o Oracle estiver no próprio anfitrião (Linux), descomente `extra_hosts` no `docker-compose.yml` e use `ORACLE_HOST=host.docker.internal`.
- Todas as queries usam *bind variables* (`oci_bind_by_name`); o `INSERT` usa `RETURNING id INTO :new_id`.
- Se as variáveis estiverem definidas mas a ligação falhar, a API responde `503` com erro JSON (não há fallback silencioso para JSON). Defina `APP_DEBUG=1` para ver o erro Oracle nas respostas `500`.

## Endpoints

| Método | Rota | Descrição | Códigos |
|---|---|---|---|
| GET | `/api/health` | Estado da app e da base de dados | 200 / 503 |
| GET | `/api/communications` | Lista (mais recentes primeiro) | 200 |
| GET | `/api/communications/{id}` | Detalhe | 200 / 400 / 404 |
| POST | `/api/communications` | Criar | 201 (+`Location`) / 400 / 422 |
| PUT | `/api/communications/{id}` | Substituir (todos os campos obrigatórios) | 200 / 400 / 404 / 422 |
| DELETE | `/api/communications/{id}` | Apagar | 204 / 404 |
| GET | `/api/facts` | "Sabias que" | 200 |
| GET | `/api/menu` | Menu lateral | 200 |

Objeto *comunicação*: `{"id":1,"date":"2026-10-23","department":"Recursos Humanos","title":"...","url":"#","highlighted":true}`
(`date` em `AAAA-MM-DD`; `department` ≤ 100 e `title` ≤ 300 caracteres, obrigatórios; `url` opcional; `highlighted` booleano, por omissão `false`).

Erros: `{"error":{"status":422,"message":"Dados inválidos.","details":{"date":"Data inválida."}}}`

## Exemplos curl

```bash
curl -s localhost:8080/api/health
curl -s localhost:8080/api/communications
curl -s localhost:8080/api/communications/2

curl -s -i -X POST localhost:8080/api/communications \
  -H 'Content-Type: application/json' \
  -d '{"date":"2026-10-26","department":"Qualidade","title":"Nova comunicação","url":"https://exemplo.pt","highlighted":true}'

curl -s -X PUT localhost:8080/api/communications/4 \
  -H 'Content-Type: application/json' \
  -d '{"date":"2026-10-27","department":"Qualidade","title":"Título atualizado","highlighted":false}'

curl -s -i -X DELETE localhost:8080/api/communications/4

curl -s localhost:8080/api/facts
curl -s localhost:8080/api/menu
```

## Desenvolvimento sem Docker (apenas modo demo)

Com PHP 8.3 instalado localmente: `php -S localhost:8080 -t public router.php`. No modo demo, o PHP precisa de permissão de escrita em `data/`.


---

# Plano de tesouraria (Admin. Financeiro → Plano de tesouraria)

Página `/treasury` + API `/api/treasury/*`. Mostra um **dashboard**, saldos por banco, recebimentos e pagamentos pelas **datas de vencimento** dos documentos em aberto do Sage X3, entradas manuais e **movimentos recorrentes**, e como resultado principal o **plano de pagamentos por banco** (com banco sugerido pela página) e um **plano de sugestões de otimização** com impacto em euros. Os pagamentos a fornecedores são **agrupados por fornecedor e semana ISO e pagos à sexta-feira**, com **utilização e reposição automáticas da conta caucionada (CCC)** e **saldo mínimo da conta à ordem** por banco (v5). Interface em português europeu.

> **Os dados são confidenciais.** O ficheiro Sage, a relação cliente-banco e todos os dados gerados ficam apenas em `data/private/` (ignorado pelo git e pelo `.dockerignore`, montado como volume). Nunca estão na imagem Docker nem no zip de código.

## Secções da página (por ordem)

1. **Dashboard**: saldo total, CCC, liquidez, recebimentos/pagamentos do horizonte, saldo final e mínimo (e a semana), bancos em falta (e tabela com 1.ª falta e necessidade), pagamentos com banco sugerido, sem banco atribuído, vencidos a receber/pagar, prazos médios, aging dos vencidos, dez maiores clientes e fornecedores e quatro gráficos SVG (saldo projetado, recebimentos e pagamentos, saldos por banco, antiguidade dos vencidos).
2. **Saldos bancários e parâmetros**: bancos, saldos, CCC e **saldo mínimo da conta à ordem** (5000 € por defeito, por banco); início, **data final** (opcional), horizonte, vista (**semanal, mensal ou diária**), **agrupar faturas por fornecedor e semana**, **dia de pagamento** (sexta-feira por defeito) e regra do dia, **usar a CCC quando falta saldo**, **repor a CCC**, **múltiplo de utilização da CCC** (5000 € por defeito), regras de vencidos, **banco de pagamento por defeito** (fornecedores) e **banco de recebimento por defeito** (clientes sem banco), compensação de créditos, pendentes, fora do Sage e **incluir movimentos recorrentes**.
3. **Plano de pagamentos por banco**: tabela da CCC por banco (limite, utilizações, reposições, utilizada no fim, saldo mínimo), pagamentos agrupados e as linhas **Utilização da CCC** / **Reposição da CCC** no detalhe diário (com opção de mostrar as faturas de cada grupo); modo do banco dos pagamentos a fornecedores (**Sugerido** por defeito, **Fixo**, **Manual**), critério (maior folga, ordem de prioridade com Subir/Descer, minimizar uso de CCC, minimizar transferências), "Dos quais sugeridos", pagamentos sugeridos a itálico com a folga, e **Aceitar sugestão** por fornecedor ou por documento.
4. **Plano de sugestões de otimização**: transferências entre bancos, uso de CCC, mudança de banco de pagamento, escalonar vencidos, cobrança prioritária, clientes de cobrança duvidosa, créditos por compensar, concentração diária, etc., sempre com impacto em € e fundamentação. **São apenas sugestões: nada muda sozinho.**
5. **Banco por entidade** (só os clientes têm banco: vem do ficheiro de clientes; os fornecedores podem ter um banco atribuído à mão), **Entidades nos dois lados**, **Movimentos recorrentes**, **Projeção**, **Recebimentos**, **Pagamentos**, **Importar/exportar**, **Ficheiros de origem** e **Regras e origem dos dados**.

## Decisão de arquitetura

- **Cálculo no servidor (PHP)**: `src/Treasury/Engine.php` (funções puras) é a porta fiel da lógica do ecrã original (entidades nos dois lados, compensação de créditos, vencidos, banco do documento, algoritmo de banco sugerido, recorrentes, dashboard, sugestões, semanas ISO). `TreasuryService.php` trata de persistência, validação, listas paginadas e importação/exportação. O JS (`public/assets/js/treasury.js`) só faz `fetch` e desenha tabelas/gráficos SVG (sem `innerHTML` com dados).
- **Leitura do Sage e do ficheiro de clientes no servidor** com PhpSpreadsheet (`.xls` e `.xlsx`), incluída no build Docker (`composer install`). Há formulários de upload na página.
- **Persistência em JSON** na pasta privada (mesmo que o Oracle esteja configurado: estes dados não vão para a base de dados da intranet).

## Segurança e confidencialidade

| Medida | Detalhe |
|---|---|
| HTTP Basic | `/treasury` e `/api/treasury/*`. Credenciais só de `TREASURY_USER` / `TREASURY_PASSWORD`; **se alguma não estiver definida, o acesso é recusado (503)**. Sem credenciais no código. |
| HTTPS | O Basic viaja em claro em HTTP: sirva a app atrás de HTTPS (proxy inverso) fora do localhost. |
| CSRF | Pedidos POST/PUT/DELETE exigem `X-Requested-With: XMLHttpRequest` e `Origin` igual ao host. |
| Cache | `Cache-Control: no-store`, `Pragma: no-cache`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`. |
| CSP | `connect-src 'self'`: os dados só vão para a própria origem. De CDNs (cdnjs) só se carregam ficheiros estáticos (Bootstrap/AdminLTE/jQuery/Font Awesome), nunca dados. Se preferir, copie esses ficheiros para `public/assets/vendor`. |
| Logs | Não se registam linhas nem valores; em erros regista-se só a classe e a linha. `zend.exception_ignore_args=On` (ver `docker/php.ini`). |
| Imagem/zip | `data/private`, `*.xls`, `*.xlsx`, `*.xlsm`, `*.csv` e `*.bak` estão no `.dockerignore`/`.gitignore`; o zip de código não os inclui (nem o `clientes_bancos.xlsx` real). |

## Variáveis de ambiente

| Variável | Descrição |
|---|---|
| `TREASURY_USER`, `TREASURY_PASSWORD` | Obrigatórias para aceder (HTTP Basic). |
| `TREASURY_DATA_DIR` | Pasta privada (por defeito `data/private`; no Docker `/var/www/html/data/private`). |

Ficheiro `.env` (copiar de `.env.example`) lido pelo `docker-compose.yml`.

## Onde colocar os ficheiros privados

**Ficheiro Sage.** Copie o mapa de itens em aberto exportado do Sage X3 para **`data/private/sage-open-items.xls`** (ou `.xlsx`), ou use "Ficheiros de origem" na página (carrega, valida e substitui; o anterior fica em `.bak`). Se o ficheiro for substituído diretamente na pasta, é relido automaticamente no pedido seguinte. Colunas lidas pelo nome do cabeçalho: `Terc.`, `Nome`, `Tipo`, `Documento`, `Data venc.`, `Colect.`, `Valor a pagar` e, opcionais, `Status` e `Divisa`. A data de referência vem de `Data ref.`.

**Relação cliente → banco.** Copie `clientes_bancos.xlsx` (folha *Export Worksheet*) para **`data/private/clientes-bancos.xlsx`**, ou carregue-o em "Ficheiros de origem → Clientes e bancos". Colunas: `BPCNUM_0` (código do cliente = `Terc.` do Sage), `BPCNAM_0` (nome), `PAYBAN_0` e `DES_0` (nome do banco). A linha com `BPCNUM_0` = 0 (a própria empresa) é ignorada; o código compara-se como texto sem zeros à esquerda. Correspondência de bancos: Novo Banco; *Millennium BCP* → BCP; *Banco Santander Totta* → Santander; *Caixa Geral de Depósitos* → CGD; *Bank Millennium Polska EUR* e *PLN* → bancos novos (criados com saldo e CCC a 0; os valores contam em euros, sem conversão da divisa PLN). Qualquer outro nome de banco do ficheiro cria um banco novo com saldo 0 (com aviso). Na primeira leitura, e sempre que o ficheiro é carregado ou se usa "Repor bancos dos clientes (ficheiro)", o banco de cada cliente é atribuído a partir do ficheiro; depois pode alterá-lo à mão em "Banco por entidade". Os clientes sem banco no ficheiro ficam em "Sem banco atribuído" (listados na página) e usam o banco de recebimento por defeito, se o escolher. **Só os clientes têm banco; os fornecedores não.**

Sem ficheiro Sage privado a página corre em **modo demonstração** com `data/sample/` (dados fictícios: `sage-open-items-sample.xls`, `clientes-bancos-sample.xlsx`, `clientes-bancos.json` e um movimento recorrente de exemplo).

Ficheiros em `data/private/`: `sage-open-items.xls` (original), `sage-items.json` (cache), `clientes-bancos.xlsx` (original) e `clientes-bancos.json` (cache), `treasury.json` (bancos, saldos, CCC, parâmetros, prioridade dos bancos, bancos por entidade, classificação dos terceiros nos dois lados, movimentos recorrentes, entradas manuais e alterações a documentos Sage).

## Executar

```bash
cp .env.example .env     # preencher TREASURY_USER e TREASURY_PASSWORD (e ORACLE_* se aplicável)
mkdir -p data/private && chmod 770 data/private   # o contentor (www-data, uid 33) tem de poder escrever (ou: chown 33:33 data/private)
cp /caminho/do/sage-open-items.xls data/private/
cp /caminho/do/clientes_bancos.xlsx data/private/clientes-bancos.xlsx
docker compose up --build
```

Abrir <http://localhost:8080/treasury> (a Basic auth pede utilizador e palavra-passe). Sem Docker: `composer install` e

```bash
TREASURY_USER=utilizador TREASURY_PASSWORD='palavra-passe-forte' \
  php -d upload_max_filesize=20M -d post_max_size=25M -S localhost:8080 -t public router.php
```

Requisitos PHP: `gd`, `zip`, `xml`, `dom`, `simplexml`, `xmlreader`, `xmlwriter`, `mbstring`, `iconv`, `ctype`, `fileinfo`, `zlib` (o `Dockerfile` instala `gd` e `zip`; o resto já vem na imagem).

Auto-teste (dados fictícios): `php tests/treasury_selftest.php`. Regenerar os dados fictícios: `php tools/make_sample.php`.

## Regras de cálculo

1. **Cliente/fornecedor** pela conta coletiva (`Colect.`): CGMN/CGUE/CGPT = clientes (a receber); FGMN/FGUE/FGPT = fornecedores (a pagar). Contas desconhecidas começadas por C/F são classificadas pela 1.ª letra e assinaladas como aviso.
2. **Valor** = `Valor a pagar` (saldo em aberto). Em fornecedores o sinal é invertido (a pagar > 0).
3. **Créditos/adiantamentos** (NCC, NCF, NCR, REC, PAG e outros de sinal contrário) são linhas negativas. Com **Compensar créditos** ligado, abatem aos documentos mais antigos da mesma entidade; o que sobrar fica como crédito.
4. **Terceiro nos dois lados.** Classificação automática: **fornecedor** quando o valor absoluto dos documentos de fornecedor é igual ou superior ao dos de cliente. Tratado como fornecedor, a conta de cliente não aparece em recebimentos e entra nos pagamentos como crédito (paga-se a diferença); tratado como cliente, mantêm-se duas entradas sem compensação. Pode alterar a classificação por entidade em "Entidades nos dois lados" (e repor a automática).
5. **Vencimento** = `Data venc.`; prazo = data de início − vencimento. Antes do início = vencido, tratado por regra própria e separada para recebimentos e pagamentos: no início / +N dias (7, 14, 30 ou qualquer valor 0–365 via API) / fora do plano. Uma **data planeada** manual prevalece.
6. **Pendentes de aprovação** entram por defeito (parâmetro) e cada documento pode ser excluído individualmente; documentos em USD contam como euros, sem conversão.
7. **Fora do Sage**: financiamentos, encargos, salários e prestações que não estão no Sage são **entradas manuais** (origem "Fora do Sage"), cada uma com banco e data próprios, removíveis (e desligáveis em bloco).
8. **Saldos de abertura e CCC** são introduzidos em "Saldos bancários e parâmetros". A CCC só é utilizada se "Usar a CCC quando falta saldo" estiver ligado (ver regra 16).
9. **Banco de cada documento**: banco no documento > banco da entidade (cliente: ficheiro; fornecedor: o atribuído) > banco por defeito (recebimento ou pagamento) > "Sem banco atribuído". No mesmo dia os recebimentos entram antes dos pagamentos. **"Sem banco atribuído" não conta como banco** na falta de fundos: tem cartão próprio no dashboard.
10. **Banco sugerido para pagamentos a fornecedores** (modo *Sugerido*, por defeito; *Fixo* usa o banco de pagamento por defeito; *Manual* só conta os bancos atribuídos). Algoritmo determinístico: 1) fixam-se os recebimentos de cada banco e os pagamentos que já têm banco (documento, fornecedor, defeito, fora do Sage); 2) os restantes ordenam-se por vencidos primeiro, data prevista, valor decrescente e identificador do documento; 3) para cada um calcula-se, em cada banco, a **folga** = menor saldo diário projetado desde a data do pagamento até ao fim do horizonte (com os recebimentos futuros do banco, os pagamentos já atribuídos e a CCC se estiver contada) menos o valor; créditos de fornecedores contam como entradas antes dos pagamentos do mesmo dia; 4) cabem os bancos com folga ≥ 0 e escolhe-se pelo critério (maior folga; ordem de prioridade; minimizar uso de CCC; minimizar transferências = ajuste justo); 5) se nenhum cabe, escolhe-se o banco com menos falta e o pagamento fica assinalado; 6) créditos de fornecedores vão para o banco ativo com menos folga. Um banco atribuído a documento ou fornecedor prevalece sempre. "Aceitar sugestão" converte-a em atribuição manual (por fornecedor: o banco com a maior parte dos pagamentos; por documento: um a um). Não altera nada sozinha.
11. **Movimentos recorrentes** (manuais): descrição, categoria (Renda, Salários, Impostos/IVA/IRC/Seg. Social, Seguros, Outros), tipo, valor positivo, banco, frequência (semanal/mensal/trimestral/anual), dia, início/fim e regra de fim de semana (manter, adiantar ou adiar para dia útil). Geram ocorrências do início do plano ao fim do horizonte (nunca em atraso; num mês sem o dia usa-se o último dia). Cada ocorrência tem origem **Recorrente ↻**, entra na projeção, no plano por banco, no dashboard e nas sugestões, e **fica fora dos vencidos, aging, prazos médios, top 10 e das sugestões de mudança de banco**. Liga-se/desliga-se individualmente e globalmente.
12. **Semana ISO (coluna derivada).** Calculada a partir de `Data venc.` (e da data planeada, se existir) no formato `AAAA-Www` da ISO 8601: semanas de segunda a domingo; a semana 1 é a que contém a 1.ª quinta-feira do ano (31/12/2026 e 01/01/2027 → `2026-W53`; 04/01/2027 → `2027-W01`; 29/12/2025 → `2026-W01`). **Não existe no ficheiro Sage nem é gravada**: é recalculada sempre. Aparece nas tabelas de recebimentos/pagamentos (semana de vencimento e semana planeada em colunas separadas), no detalhe do plano e nas exportações.
13. **Agrupamento semanal = semana ISO** (projeção, plano por banco, dashboard e sugestões; vista semanal), com rótulo `S44 2026 (26/10–01/11)` (curto `S44`, ou `S01/27` quando o ano muda). O horizonte (1–104 semanas; por defeito 10) começa na semana ISO da data de início; a 1.ª semana pode ser parcial. Vencidos caem na semana da data de início (ou início + dias, conforme a regra). A vista diária mantém uma coluna por dia.
14. **Data final e vista mensal.** Com **Data final** vazia, o fim vem do horizonte (semanas desde a segunda-feira da semana de início; na vista diária, até 12 semanas e 400 dias no máximo). Com data final válida (≥ início; máximo de 5 anos após o início), o horizonte passa a ser calculado a partir dela (e escolher o horizonte limpa a data final). Data final inválida ou anterior ao início é recusada/assinalada e usa-se o horizonte. A **vista mensal** agrupa os mesmos movimentos por mês civil (rótulo `out 2026`; curto `jan/27` quando o ano muda; o 1.º e o último mês podem ser parciais). Os totais são iguais em todas as vistas.
15. **Pagamentos agrupados e à sexta-feira.** Só os documentos de fornecedores (pagamentos) sem data planeada, que não sejam "Fora do Sage" nem recorrentes. Agrupam-se por **fornecedor + semana ISO da data de pagamento + banco já atribuído**, com os créditos compensados dentro do grupo (grupo a zero = compensação; grupo que cai depois do fim do plano fica fora). O pagamento é feito no **dia de pagamento** (sexta-feira por defeito; regra *dia anterior ou igual ao vencimento mais antigo* = sexta anterior, ou *da semana ISO do vencimento*); os **vencidos** pagam-se na 1.ª sexta-feira a partir do início, e nunca antes do início. Documentos com data planeada, pagamentos fora do Sage e recorrentes mantêm a sua data. Um grupo com uma só fatura é essa fatura. Desligar o agrupamento volta a pagar cada fatura na sua data.
16. **Saldo mínimo e CCC.** Cada banco tem um **saldo mínimo da conta à ordem** (5000 € por defeito; vazio = 5000; negativo = 0). Se um pagamento deixar a conta abaixo do mínimo e **Usar CCC** estiver ligado, utiliza-se a CCC em **múltiplos** do parâmetro (5000 €), até ao limite do banco; os recebimentos e créditos do dia entram antes dos pagamentos (do maior para o menor). Com **Repor CCC** ligado, no fim de cada dia em que exista saldo acima do mínimo repõe-se a CCC utilizada, também em múltiplos (a última parcela pode ser inferior ao múltiplo), até ao máximo do que foi utilizado. Um banco em falta é o que fica abaixo do mínimo mesmo depois de esgotar a CCC. **Sem banco atribuído** tem mínimo 0 e não usa CCC. O custo de juros da CCC não está calculado.
17. **Banco sugerido (v5).** Para cada pagamento (ou grupo) a folga em cada banco é calculada em relação ao saldo mínimo: primeiro só com a conta à ordem; se nenhum banco couber, com a conta à ordem mais a CCC disponível (quando ligada). O pagamento fica assinalado "usa CCC" quando a folga só existe com a CCC.

Os documentos do Sage nunca são alterados: gravar banco/data planeada/valor/vencimento ou "Excluir" num documento Sage cria uma *alteração* em `treasury.json`. "Repor" desfaz. "Repor dados do Sage" (em Importar e exportar) remove essas alterações, a classificação dos dois lados, os movimentos recorrentes e a prioridade, e repõe os bancos dos clientes a partir do ficheiro (mantém saldos/CCC e entradas manuais).

## Importar e exportar (CSV `;`)

Modos: **Documentos**, **Recorrentes**, **Saldos**, **Bancos por entidade** e **Plano por banco** (só exportação, **formato novo na v5**: `banco;tipo_linha;banco_origem;fornecedor;semana_iso;data_pagamento;semana_iso_pagamento;n_faturas;valor;utilizacao_ccc;reposicao_ccc;saldo_ordem_apos;ccc_utilizada_apos;documentos;vencimento_mais_antigo;nota`, com linhas de pagamento, de recebimento e de utilização/reposição da CCC). **Saldos**: `banco;saldo;ccc_disponivel;saldo_minimo` (a 4.ª coluna é opcional na importação; se faltar, mantém-se o mínimo do banco ou 5000 € num banco novo). Recorrentes: `descricao;categoria;tipo;valor;banco;frequencia;dia;inicio;fim;fim_de_semana;ativo`. A exportação de Documentos não inclui recorrentes.

## Endpoints `/api/treasury` (todos com HTTP Basic; escrita exige `X-Requested-With: XMLHttpRequest`)

| Método | Rota | Descrição |
|---|---|---|
| GET | `/state` | Estado: modo demo, ficheiros de origem, parâmetros, bancos, resumo Sage, terceiros nos dois lados |
| GET | `/plan` | Resultado por banco e período (recebimentos, pagamentos, sugeridos, saldo final, 1.ª falta, necessidade máx., estado) e prioridade |
| GET | `/plan/detail?bank=&period=-1&rec=1&fat=0&page=0` | Movimentos do banco (paginado, 50) com saldo após cada um, origem do banco, linhas de CCC e (com `fat=1`) as faturas de cada grupo |
| GET | `/dashboard` | Indicadores, gráficos, bancos em falta, aging e top 10 |
| GET | `/suggestions` | Plano de sugestões de otimização (texto, impacto em €, fundamentação) |
| PUT | `/priority` | `{order:[ids dos bancos]}` (ordem de prioridade) |
| POST | `/suggestions/accept` | `{mode:fornecedor\|documento}`: converte o banco sugerido em atribuição manual |
| GET | `/open-items?tipo=R\|P&bank=&prazo=venc\|avenc\|cred\|excl&q=&page=` | Documentos (30 por página, com semana ISO de vencimento e planeada) |
| GET/PUT | `/settings` | Início, `fim` (data final), horizonte (1–104), vista (Semanal\|Mensal\|Diária), CCC, compensação, pendentes, fora do Sage, `incRec`, regras de vencidos, `defBankR`, `defBankP`, `modoP` (Sugerido\|Fixo\|Manual), `crit`, `agrup`, `diaPag` (0 = segunda … 4 = sexta), `regraDia` (ant\|sem), `useCCC`, `repor`, `passo` (múltiplo da CCC, > 0) |
| GET/POST | `/banks` | Lista / criar `{nome, saldo, ccc, minimo}` |
| PUT/DELETE | `/banks/{id}` | Alterar / remover (limpa referências) |
| GET/POST | `/manual-entries` | Entradas manuais `{tipo:R\|P, doc, parte, venc, valor, banco, dataPlan, origem?}` |
| PUT/DELETE | `/manual-entries/{id}` | Alterar / apagar |
| GET/POST | `/recurring` | Movimentos recorrentes `{desc, cat, tipo, valor, banco, freq, dia, ini, fim, fds, ativo}` |
| PUT/DELETE | `/recurring/{id}` | Alterar (incl. `{ativo}`) / apagar |
| PUT/DELETE | `/overrides/{id}` | Alterar (`banco`, `dataPlan`, `venc`, `valor`, `excluded`) / repor um documento Sage |
| DELETE | `/overrides` | Repor todas as alterações a documentos Sage |
| GET | `/entities?side=R\|P&q=&only=1&page=` | Entidades, banco atribuído e clientes sem banco |
| PUT | `/entities/{codigo}` | `{banco}` (vazio remove) |
| POST | `/entities/bulk` | `{side,q,only,mode:empty\|all\|clear,banco}` |
| PUT | `/dual/{terc}` | `{classe:F\|C}` classificação do terceiro nos dois lados |
| DELETE | `/dual` | Repor a classificação automática |
| GET | `/export?kind=documents\|recurring\|balances\|entities\|plan` | CSV `;` em `{text}` |
| POST | `/import` | `{kind:documents\|recurring\|balances\|entities, text, replace}` |
| POST | `/sage-file` | multipart, campo `file` (.xls/.xlsx) |
| POST | `/clientes-bancos` | multipart, campo `file` (.xlsx/.xls): substitui a relação cliente-banco e repõe os bancos dos clientes |
| POST | `/clientes-bancos/reset` | "Repor bancos dos clientes (ficheiro)" |
| POST | `/reset` | "Repor dados do Sage" (ver acima) |

```bash
curl -u utilizador:palavra-passe localhost:8080/api/treasury/plan
curl -u utilizador:palavra-passe -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
  -X POST localhost:8080/api/treasury/recurring \
  -d '{"desc":"Renda do armazém","cat":"Renda","tipo":"P","valor":"2500,00","banco":"","freq":"Mensal","dia":1,"ini":"01/11/2026","fim":"","fds":"Adiar para dia útil"}'
curl -u utilizador:palavra-passe -H 'X-Requested-With: XMLHttpRequest' -F file=@clientes_bancos.xlsx localhost:8080/api/treasury/clientes-bancos
```

## Migração para a v5 e estado por defeito

- O `treasury.json` anterior (versão 1) é migrado ao ser lido: passa a versão 2, **todos os bancos ficam com saldo mínimo 5000 €**, e **ligam-se por defeito** `useCCC` (a v4 tinha a CCC desligada por defeito), `repor`, o agrupamento e o dia de pagamento à sexta-feira. Para reproduzir os números da v4, desligue "Agrupar", "Usar CCC" e ponha o saldo mínimo a 0 (e a data final vazia).
- O formato CSV do **plano por banco** mudou (incompatível com a v4); o dos **saldos** ganhou uma 4.ª coluna opcional.
- Estado inicial dos dados reais (início 26/10/2026, até 03/01/2027, critério *Maior folga*): 1515 documentos de fornecedores → 666 pagamentos agrupados (275 com várias faturas); CCC do Novo Banco utilizada em 3 vezes em 26/10 e reposta em 27/10 e 30/10, a 0 no fim.

## Notas

- Não testado em Docker real (a máquina de desenvolvimento não tem Docker): o build com `gd`/`zip`/Composer e a escrita no volume montado devem ser validados na primeira execução.
- Auto-teste: `php tests/treasury_selftest.php` (138 verificações, inclui as regras da v5: dias de pagamento, agrupamento, CCC, saldo mínimo, data final, vistas e migração).
- Backup: copie `data/private/` (contém dados confidenciais: guarde-o cifrado).
