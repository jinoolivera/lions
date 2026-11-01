# Lions ERP

MVP ERP for import and distribution operations in Peru. The current implementation covers catalog, product presentations, warehouses, stock balances and auditable movements; suppliers, purchase orders, partial receipts and supplier invoices; operational sales with cash sessions; and customers, credit limits, account statements and partial collections.

## Stack

- Laravel 13 API and server-side SPA host
- React 19 with Vite 6
- Sanctum session authentication for the first-party SPA
- SQLite for local setup; MySQL/InnoDB is the intended production database
- PEN display and `America/Lima` application timezone

## Local setup

Run commands from `backend/` using PHP 8.3+, Composer 2+, Node 18+ and npm:

```sh
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
php artisan lions:user:create
php artisan lions:demo:seed
npm run dev
```

In another terminal, run `php artisan serve`. Open `http://localhost:8000` or `http://127.0.0.1:8000`. The demo seeder is optional, clearly labels all rows `DEMO`, is idempotent, and refuses to run in production. Do not use SQLite to validate production concurrency behavior; use the selected MySQL/InnoDB version for that work.

Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secure session settings and production database credentials before deploying. Never commit `.env` or use example credentials in production.

## First slice

- Create products with SKU/barcode and a base presentation.
- Add product-specific presentations and PEN prices.
- Register an opening stock movement by product, warehouse and presentation.
- View physical and available quantity; stock cannot go negative through the movement service.
- Register a supplier order, receive it partially or fully, and keep the supplier invoice as a separate open payable.
- Maintain moving average acquisition cost from known receipt costs; opening stock accepts an optional cost.
- Open and close a cash session per warehouse, record the opening/count amounts, and review expected cash.
- Record sales with the configured presentation price and payment method. Stock deduction and sale/payment records commit atomically.
- Register customers with a credit limit, post unpaid sale balances to their account, and reject credit sales over the limit.
- Review open customer balances and record idempotent partial collections. Cash collections update the expected cash for that open warehouse session.
- Create confirmed customer orders that reserve available stock, record idempotent partial picking, then dispatch partial or complete quantities. Each dispatch consumes its reservation once and creates a credit sale linked to the shipment.
- Use the supplied U.Lions Import logo and its dark, ivory and gold palette in the interface.
- API routes are under `/api/v1` and require a signed-in user, except login.

The sidebar follows the original nine module names. A–F and Gerencia are marked **Parcial**; Finanzas and Facturación Perú are **Pendiente**. Sales are operational records, not SUNAT invoices. Review [the module status](docs/erp-mvp/12-estado-modulos.md) before using the system.

Run the local quality suite with `php artisan test`. It exercises the implemented product-to-purchase-to-cash-to-customer-to-delivery flow and report totals on SQLite in memory. MySQL concurrency, transfers, returns, full finance and SUNAT remain outstanding before production. The planning and operating documents are in `docs/erp-mvp/`.

## Production deployment

Production runs in `/root/lions` on the shared Docker host, with a dedicated MySQL database and persistent volumes. The public Nginx proxy routes `lions.devicesperu.com` to the Lions container. The GitHub Actions workflow in `.github/workflows/deploy-production.yml` deploys each push to `main`.

In the repository, add these Actions secrets under **Settings → Secrets and variables → Actions**:

- `LIONS_PROD_SSH_KEY`: the private key from `C:\Users\USUARIO\.ssh\lions_github_actions`.
- `LIONS_PROD_KNOWN_HOSTS`: the SSH host-key line provided in the deployment instructions.

The server keeps production environment files under `/etc/lions/`, outside the checkout. Migrations run during deployment. Create the first administrator once, after the initial deploy, with `cd /root/lions && docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan lions:user:create`. The command requires a password of at least 12 characters. Never add production environment files, private keys, or credentials to Git.
