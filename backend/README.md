# Lions ERP · aplicación

ERP MVP para importación y distribución. Incluye catálogo, presentaciones, existencias y movimientos auditables; compras y recepción parcial; venta operativa y caja; clientes, crédito y cobranza; pedidos, preparación, entregas parciales e informes operativos.

## Desarrollo local

Requisitos: PHP 8.3+, Composer 2, Node.js y npm.

```sh
cp .env.example .env
composer install
npm ci
php artisan key:generate
php artisan migrate
php artisan lions:user:create
npm run dev
```

En otra terminal, `php artisan serve`; abre `http://127.0.0.1:8000`. El usuario inicial se crea interactivamente y requiere una contraseña de al menos 12 caracteres.

## Producción y piloto

MySQL/InnoDB es la base prevista para concurrencia de producción; SQLite sirve para desarrollo local. Mantén `.env` fuera del repositorio, HTTPS activo, `APP_ENV=production`, `APP_DEBUG=false` y cookies de sesión seguras. Compila con `npm ci && npm run build`, instala Composer sin dependencias de desarrollo y ejecuta migraciones con `php artisan migrate --force` durante una ventana controlada.

Consulta [la guía de despliegue, respaldo, restauración y operación del piloto](../docs/erp-mvp/11-piloto-runbook.md). Los informes son operativos, no estados contables. La valorización de inventario es aproximada cuando faltan costos promedio. Las ventas del sistema no son comprobantes electrónicos SUNAT.

Las rutas de API están bajo `/api/v1`; salvo login, requieren sesión autenticada. El endpoint de salud es `/up`.
