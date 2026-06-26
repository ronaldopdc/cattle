# Scripts auxiliares (NÃO vão para produção)

Esta pasta contém scripts utilitários que **não fazem parte da aplicação web** e
**nunca devem ser publicados** no servidor (`sync_files.sh`/`deploy.sh` não os
enviam, pois ficam fora de `src/`).

Todos eles são executados **manualmente** via linha de comando, por exemplo:

```bash
php scripts/migrations/create_users_table.php
```

Os caminhos de `config.php` e `financial_calculations.php` apontam para
`../../src/`, então rode-os a partir da raiz do projeto (ou de qualquer lugar:
o caminho é resolvido por `__DIR__`).

## Estrutura

- `migrations/` — Criação/alteração de schema e dados de setup
  (`CREATE TABLE`, `ALTER TABLE`, `UPDATE` de inicialização).
  São de execução única (one-shot), aplicadas ao preparar um banco novo.
- `fixes/` — Correções pontuais de dados já existentes
  (`fix_balance_after`, `fix_settlement_history`, `fix_cpf_length`).
- `debug/` — Scripts de diagnóstico que imprimem cálculos/saldos no terminal.
  Úteis para investigar parcerias/lotes específicos.
- `archive/` — Código morto/rascunho mantido apenas para referência histórica
  (`NEW_CALCULATION_LOGIC.php`).

## Por que ficam fora de `src/`

Estes scripts **não têm autenticação** (`require_login`). Se ficassem em `src/`
e fossem publicados, qualquer pessoa poderia acessá-los por URL e:
- ver dados financeiros sensíveis (scripts de `debug/`);
- disparar `UPDATE`/`ALTER`/`CREATE` no banco (scripts de `migrations/` e `fixes/`).

Por isso foram movidos para cá e o `sync_files.sh` também remove esses padrões
do servidor de produção (caso versões antigas tenham sido publicadas).
