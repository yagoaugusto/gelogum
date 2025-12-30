# Banco de dados (MySQL)

Migrações em `API/database/migrations/`.

## Como aplicar

1. Crie/importe via phpMyAdmin **ou** rode no terminal:

```bash
mysql -u root -p < API/database/migrations/001_init.sql
mysql -u root -p < API/database/migrations/002_add_birthday.sql
mysql -u root -p < API/database/migrations/003_create_products.sql
mysql -u root -p < API/database/migrations/004_create_deposits.sql
mysql -u root -p < API/database/migrations/005_create_withdrawals.sql
mysql -u root -p < API/database/migrations/006_update_withdrawal_orders_flow.sql
mysql -u root -p < API/database/migrations/007_create_withdrawal_returns.sql
mysql -u root -p < API/database/migrations/008_create_withdrawal_payments.sql
mysql -u root -p < API/database/migrations/009_add_withdrawal_payment_method.sql
mysql -u root -p < API/database/migrations/010_create_permission_groups.sql
mysql -u root -p < API/database/migrations/011_add_analytics_permission.sql
```

2. A aplicação web lê as variáveis de ambiente:

- `GELO_DB_HOST` (default `127.0.0.1`)
- `GELO_DB_PORT` (default `3306`)
- `GELO_DB_NAME` (default `gelo`)
- `GELO_DB_USER` (default `root`)
- `GELO_DB_PASS` (default vazio)
