---
name: cattle-system
description: Instruções detalhadas para desenvolvimento e modificação do Sistema Cattle Invest (Cattle System), incluindo arquitetura do banco de dados, regras financeiras, fluxos PJ e processos de implantação/sincronização.
---

# Skill do Sistema Cattle Invest (Cattle System)

Este guia foi elaborado para orientar você (ou qualquer outra Inteligência Artificial) sempre que houver necessidade de fazer alterações, correções ou desenvolver novas funcionalidades no sistema **Cattle Invest**. Ele garante que as regras de negócio complexas (cálculos de juros compostos, quitação de contratos, tipos de parceiros, etc.) sejam rigorosamente seguidas, evitando falhas financeiras ou de segurança.

---

## 1. Visão Geral do Sistema
O **Cattle Invest** é uma plataforma de gestão de parcerias pecuárias (confinamento e engorda de gado). Ela conecta investidores, proprietários e confinamentos, acompanhando o rendimento dos lotes de animais ao longo do tempo por meio de fórmulas de juros compostos.

**Tecnologias Utilizadas:**
- **Backend:** PHP (7/8) usando PDO para conexão direta e segura com MySQL.
- **Frontend:** HTML5, CSS3, JavaScript puro com suporte a **jQuery**, **Select2** (para busca e seleção de parceiros em combos de formulários) e **Chart.js** (gráficos do Dashboard).
- **Relatórios:** Geração de documentos Word (.docx), planilhas Excel (.xls/.xlsx) e PDFs.

---

## 2. Arquitetura do Banco de Dados

### Tabelas Principais e Relacionamentos
1. **`partners`**: Cadastro unificado de parceiros.
   - Suporta **PF** (CPF) e **PJ** (CNPJ).
   - Armazena dados bancários, tipo de chave e chave PIX para pagamentos.
2. **`partner_type_assignments`**: Mapeia os papéis de um parceiro.
   - Um parceiro em `partners` pode ter múltiplos papéis ao mesmo tempo: `owner` (Proprietário), `investor` (Investidor) ou `confinamento` (Confinamento).
3. **`partner_representatives`**: Associa empresas (**PJ**) aos seus representantes legais (**PF**).
   - Relaciona `company_id` com `representative_id` (ambos apontam para `partners.id`).
4. **`lots`**: Cadastro dos lotes de gado.
   - Contém quantidade de cabeças (`animal_count`), peso de protocolo, preço indexado, data de previsão de abate e categoria (ex: `'engorda'`).
5. **`partnerships`**: Define a parceria principal entre um Proprietário (`owner_id`), Investidor (`investor_id`) e opcionalmente um Confinamento (`confinamento_id`).
   - Armazena o valor total investido (`total_value`) e a data de início (`start_date`).
6. **`partnership_lots`**: Tabela pivot que vincula lotes de gado à parceria.
   - Define a taxa mensal aplicada ao lote (`monthly_rate`), a data de abate pactuada (`slaughter_date`) e o valor futuro projetado (`projected_value`).
7. **`partnership_liquidations`**: Registro de pagamentos/amortizações da parceria.
   - Colunas cruciais: `amount_principal` (principal amortizado), `amount_interest` (juros pagos), `amount_total` (total pago), `is_settlement` (indica se é quitação de contrato, padrão `0`), `lot_id` (vínculo opcional com um lote específico).
8. **`lot_simulations` e `simulation_daily_costs`**:
   - Guardam os parâmetros para a ferramenta de simulação de engorda (custos diários de alimentação por faixa de peso, ganho de peso diário esperado [GPD], fretes, comissão, etc.).
9. **`users`**: Usuários do sistema. Os papéis são `admin` (acesso total) e `user` (acesso limitado). Usuários não-admin possuem um `partner_id` vinculado para restringir os dados visualizados no dashboard.
10. **Tabelas de Anexos (`lot_attachments`, `partner_attachments`, `partnership_attachments`)**:
    - **Importante:** Os arquivos são gravados diretamente no banco de dados em colunas do tipo `LONGBLOB` (`file_data`). **Não há upload físico de arquivos no sistema de arquivos local.** Isso evita problemas de caminhos e permissões de diretórios. Tamanho máximo permitido: 10MB.

---

## 3. Regras Financeiras e Fórmulas de Cálculo
As fórmulas financeiras são o coração do sistema e estão centralizadas principalmente em `src/financial_calculations.php`. **Nunca altere essas fórmulas sem autorização explícita do proprietário do sistema.**

### A. Regra do Período em Meses (Regra dos 30 dias)
O sistema calcula o tempo entre duas datas convertendo a diferença de dias em fração de mês comercial.
$$\text{Meses} = \frac{\text{Total de Dias entre as datas}}{30}$$
**Código PHP correspondente:**
```php
function calculateMonthsBetween($start, $end) {
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    $interval = $startDate->diff($endDate);
    return $interval->days / 30;
}
```

### B. Fórmula do Principal Alocado (Valor Presente)
Determina o valor presente investido em um lote a partir de seu valor projetado de abate futuro usando juros compostos reversos.
$$\text{Principal} = \frac{\text{Valor Projetado}}{(1 + \text{Taxa Mensal}/100)^{\text{Meses}}}$$
**Código PHP correspondente:**
```php
function calculateAllocatedAmount($projected, $rate, $months) {
    if ($months <= 0) $months = 0.0001;
    return $projected / pow((1 + $rate / 100), $months);
}
```

### C. Estado da Parceria e Saldo Corrente (`calculatePartnershipState`)
Calcula o saldo histórico ("rolling balance") da parceria aplicando capitalizações periódicas e deduzindo as liquidações na ordem cronológica de suas ocorrências:
1. **Ordenação:** As liquidações são ordenadas por data de forma ascendente.
2. **Capitalização de Juros:** Para cada período entre eventos (ou da última liquidação até hoje), calcula-se o juro composto acumulado sobre o saldo anterior.
3. **Determinação da Taxa:** A taxa aplicada é a taxa do lote correspondente à data do evento (ou a taxa do lote com data de abate mais distante).
4. **Liquidação Parcial (Amortização):**
   - Reduz o saldo bruto acumulado: `Novo Saldo = Saldo Anterior * Fator Juros - Valor Pago`.
5. **Quitação de Contrato (`is_settlement = 1`):**
   - O valor pago é considerado o valor final total. O saldo bruto ajusta-se para ser exatamente o valor pago, resultando em um saldo restante de exatamente R$ 0,00 após a transação. O juro acumulado do período absorve qualquer ganho ou perda de arredondamento.

### D. Saldo Projetado (`calculateProjectedBalance`)
- **Se não houver liquidações:** O saldo projetado ao vencimento é exatamente a soma dos valores projetados de todos os lotes vinculados.
- **Se houver liquidações anteriores:** O saldo atual é projetado para o futuro (até a data do abate mais distante) usando a taxa do último lote.

### E. Saldo Investido Total (Líquido)
Calculado na lógica de livro-caixa como:
$$\text{Valor Investido Líquido} = \text{Principal Inicial Total} - \text{Total de Principais Liquidados}$$

---

## 4. Geração de Contratos (Templates e Placeholders)
O arquivo `src/contracts.php` gerencia os modelos de contrato. Ao gerar um documento, o sistema substitui tags dinâmicas pelos dados cadastrais dos parceiros e lotes envolvidos.

**Algumas tags disponíveis para templates:**
- `{{PARCEIRO_PROPRIETARIO}}`, `{{CPF_PROPRIETARIO}}`, `{{RG_PROPRIETARIO}}`, `{{ENDERECO_PROPRIETARIO}}`
- `{{PARCEIRO_INVESTIDOR}}`, `{{CPF_INVESTIDOR}}`, `{{RG_INVESTIDOR}}`, `{{ENDERECO_INVESTIDOR}}`
- `{{CONFINAMENTO}}`, `{{CPF_CONFINAMENTO}}`, `{{RG_CONFINAMENTO}}`, `{{ENDERECO_CONFINAMENTO}}`
- `{{TABELA_LOTES}}` (insere tabela HTML formatada com os dados dos lotes)
- `{{TABELA_FORMACAO}}` (tabela demonstrativa de formação de valores e taxas)
- `{{TABELA_LIQUIDACOES}}` (tabela de liquidações previstas e valores projetados)
- `{{DATA_EXTENSO}}` (data atual por extenso)
- `{{VALOR_TOTAL_EXTENSO}}` (valor total da parceria por extenso)

---

## 5. Diretrizes para Alterações no Código (Developer Rules)

Se você for fazer modificações no código, siga rigorosamente as diretrizes abaixo:

1. **Segurança (Acesso por Papel):**
   - Páginas administrativas e edição de dados sensíveis devem possuir proteção por papel: `require_role('admin')` ou filtro pelo `partner_id` associado ao usuário logado se ele for um `user` normal.
   - Sempre utilize consultas preparadas (Prepared Statements) do PDO para evitar SQL Injection. Exemplo:
     ```php
     $stmt = $pdo->prepare("SELECT * FROM partners WHERE id = ?");
     $stmt->execute([$id]);
     ```
2. **Integração do Select2:**
   - Formulários de cadastro que requerem seleção de parceiros ou lotes devem usar a biblioteca Select2 para melhor experiência do usuário e suporte a busca rápida de nomes.
3. **Evitar Erro de Arredondamento:**
   - Use `floatval()` ao carregar valores decimais do banco e sempre formate valores monetários para exibição com `number_format($valor, 2, ',', '.')` no PHP ou formate no JS correspondente.
4. **Preservar Configurações Locais e de Produção:**
   - O arquivo `src/config.php` contém credenciais específicas do banco. **Nunca envie o arquivo `config.php` local diretamente para o servidor de produção** para evitar sobrescrever as credenciais reais do cliente.
   - Sempre use os scripts de sincronização para fazer deploy de arquivos de forma segura.

---

## 6. Fluxo de Implantação e Sincronização (Deploy)

Existem dois utilitários shell na raiz do projeto para sincronizar as atualizações com o servidor real:

### A. Sincronização de Arquivos (`sync_files.sh`)
- Envia de forma limpa o código da pasta `./src` para o diretório `/var/www/html/cattle` no servidor remoto (`ipservidor.synology.me`, porta `2202`).
- **Comportamento Crucial:** Remove o arquivo `config.php` do pacote temporário de sincronização antes do envio para **nunca sobrescrever as credenciais de produção**.
- Executa a publicação no servidor usando `sshpass` e comandos `sudo cp` temporários para garantir as permissões do servidor web (`www-data:www-data`).

### B. Deployment Completo com Banco (`deploy.sh`)
- Faz o backup (dump) do banco de dados local executando o mysqldump de dentro do container Docker local (`cattle_system-db-1`).
- Atualiza o host do banco de dados no arquivo temporário `config.php` para apontar para o IP remoto (`192.168.1.147`).
- Envia os arquivos e o dump SQL para o servidor.
- Executa a restauração do banco de dados no servidor Synology e ajusta permissões de leitura/escrita.

---

Sempre que iniciar uma nova tarefa ou ser questionado por outros desenvolvedores/IAs sobre o funcionamento do Cattle Invest, carregue esta Skill para garantir total compatibilidade e manter a robustez matemática e arquitetural do sistema!
