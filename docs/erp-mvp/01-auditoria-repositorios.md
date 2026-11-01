# 01. Auditoría de repositorios

Fecha: 2026-10-27 (America/Lima). Auditoría estática; no se ejecutaron pruebas ni se modificó Socium.

## Inventario técnico inicial

| Repositorio | Estado observado | Stack/evidencia | Conclusión |
|---|---|---|---|
| `lions` (raíz de trabajo configurada) | Vacío: 0 entradas | Sin manifiestos, fuentes, migraciones ni pruebas | No hay proyecto ERP nuevo que auditar todavía. |
| `socium` (`/home/jolivera/projects/socium`) | Proyecto Laravel existente; rama `codex/sales-report-release` siguiendo `origin/main`; árbol de trabajo sucio | Laravel 10.50.2, PHP declarado `^8.1` (AGENTS indica PHP 8.2), Sanctum 3.3.3, Livewire 4.2.4, PHPUnit 10.5.63; MySQL 5.7 en Docker; Blade/Vite/Tailwind, no React | Fuente accesible y rica en POS, pero no es una aplicación limpia ni una plataforma React/API pura. Cualquier extracción debe fijar commit limpio y reconciliar cambios locales. |

En Socium se observan 175 migraciones, 50 archivos de pruebas, alrededor de 912 archivos versionados y directorios `app/Pos/{Catalog,Inventory,Pricing,Purchases,Sales}`. El conteo de pruebas incluye tests ajenos al ERP. La configuración documentada usa sociedades, miembros, aportes, nómina, asistencia, wallets y permisos multi-sociedad. Existe `routes/pos_api.php` con endpoints protegidos por Sanctum, además de vistas web Blade.

### Estado Git de Socium

Al momento de la inspección hay modificaciones locales en controladores/modelos/vistas/rutas y archivos sin seguimiento asociados a anulación de ventas, cierre rápido, hardening de despliegue y conteo de inventario. Entre ellos figura una migración local para anulaciones. No se debe asumir que todo ese trabajo está integrado a `origin/main` ni copiar desde esa copia sin revisar el diff. La auditoría de capacidades describe el código presente en el árbol local, no una release estable certificada.

### Componentes funcionales encontrados en código

- Catálogo de productos, códigos de barras, producto compartido por compañía/sucursales, presentaciones por producto y resolución de precios por sucursal/compañía.
- Stock por sucursal, lotes y vencimientos, movimientos de inventario, ajustes, solicitudes de aprobación y transferencias entre sucursales.
- TPV de teclado, borradores reanudables, venta por presentación, pagos mixtos, sesiones/terminales de caja, conciliación, anulaciones y solicitudes de devolución.
- Proveedores, órdenes/requerimientos de compra, recepción en dos pasos, devoluciones a proveedor, pagos parciales y vínculo con movimientos financieros.
- Reportes POS, dashboards y atribución de vendedores.
- Pruebas de feature para catálogo/precios, conversiones, venta, stock, recepción, transferencias, pagos a proveedores, retornos, permisos y aislamiento.

La existencia de rutas, pantallas o pruebas no prueba despliegue productivo, uso por la empresa importadora, cumplimiento fiscal, ni cobertura total de criterios de aceptación. No se ejecutó el conjunto de pruebas.

### Ausencias o no verificadas

- ERP independiente en `lions`: ausente.
- Frontend React: ausente en Socium (`package.json` solo declara Vite/Laravel y Axios; la UI observada es Blade/Livewire).
- Maestro comercial de clientes, límite de crédito, CxC/abonos de clientes y estado de cuenta: no localizados en el módulo POS revisado; clientes aparecen como referencias/nombres en ventas, no como módulo demostrado.
- Documentos de venta electrónicos SUNAT, series/correlativos fiscales, notas de crédito/débito y GRE: no localizados en rutas/modelos/migraciones inspeccionados.
- Almacenes/ubicaciones internas: el inventario revisado se organiza por `branch_id` y lotes; multi-almacén y bin locations no están demostrados.
- Costeo de importación distribuido entre líneas/productos: no demostrado.
- Pruebas concurrentes reales con dos procesos/ conexiones y motor MySQL: no ejecutadas ni verificadas.
- Configuración final de moneda PEN/zona horaria y despliegue objetivo del ERP: pendiente de decisión; no inferirla de los defaults de Socium.

### Método y límites

Inspección de manifiestos, lock, rutas, estructura de carpetas, servicios POS, migraciones, pruebas y estado Git. No se inspeccionaron secretos de `.env`, no se conectó a base de datos, no se ejecutaron Artisan, migraciones, instalaciones, builds ni tests. Para dictaminar comportamiento completo se requiere ejecutar pruebas en una base aislada y revisar el commit que se elija como fuente.
