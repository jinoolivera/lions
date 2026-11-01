# 04. Arquitectura propuesta

## Forma del sistema

Monolito modular Laravel + API REST versionada (`/api/v1`) + React responsive, con MySQL InnoDB. No se justifican microservicios para un equipo y operación de MVP: ventas, stock, caja y cobranza necesitan una transacción coherente. Mantener módulos `Catalog`, `Inventory`, `Purchasing`, `Sales`, `Cash`, `Receivables`, `Fulfillment`, `Tax`, `Reporting`, `Identity` y contratos entre ellos. Jobs/colas se reservan para emisión fiscal, notificaciones e importaciones que deban reintentarse.

La API valida y autoriza; servicios/casos de uso ejecutan políticas y transacciones; modelos/repositorios persisten. React no decide precios finales, conversión final ni autorización de descuentos. El servidor vuelve a resolver estos valores al confirmar.

## Decisiones de datos y operaciones

- Un `base_unit` por producto. Cada presentación guarda `base_units_per_sale_unit` decimal de alta precisión, activo y modo fraccionable. Conversión exacta requiere entero de unidades base o una unidad base divisible definida; guardar cantidades con precisión suficiente y validar precisión comercial.
- Precio por presentación/lista/cliente se fija en el documento con moneda, descuento, impuesto, factor y costo unitario capturados; cambios de catálogo no alteran historial.
- Cada comando que cambia saldo se ejecuta dentro de transacción InnoDB, bloquea filas de saldo/lotes en orden determinista y agrega movimiento inmutable con `operation_key` único. El balance es una proyección reconciliable. No editar/borrar movimientos: reversar con referencia.
- En MySQL, aplicar `SELECT ... FOR UPDATE`, constraints únicos/FK/check compatibles con versión elegida, validación de saldo dentro del bloqueo e idempotencia. Definir aislamiento, reintentos acotados de deadlock y timeout. El control de saldo es un servicio único utilizado por venta, reserva, despacho, devolución y transferencia.
- Reservar mueve cantidad de disponible a reservada sin tocar físico; despacho/venta consume físico y libera la reserva en la misma transacción. Convertir pedido en venta conserva referencias y evita consumir dos veces.
- Pruebas de concurrencia deben usar el mismo motor MySQL/InnoDB y dos operaciones independientes; SQLite de tests no demuestra bloqueo de filas.

## Costos, dinero y finanzas

Usar centavos enteros para PEN en importes unitarios/documentales cuando el rango y cálculo fiscal lo permitan, con redondeo explícito por línea y documento; no usar float binario. Los factores de presentación y cantidades se modelan como decimal, con regla de redondeo comercial aprobada. Empezar con promedio ponderado móvil por SKU/almacén (simple y congruente con compras mezcladas); mantener costo congelado en líneas y ledger. FIFO/lotes es opcional cuando vencimientos/trazabilidad lo requieran. No llamar “utilidad neta” a ventas menos costo: P&L completo requiere plan contable/impuestos/gastos.

## Identidad y despliegue

Sanctum es una opción viable para SPA propia con cookies de sesión/CSRF y dominio configurado; preferir sesión segura first-party antes que tokens persistentes en navegador. Roles/permisos propios del ERP se modelan por empresa y sede. Configurar PEN, `America/Lima`, locale `es_PE`, UTC para timestamps almacenados y mostrar hora Lima. Usar secretos fuera del repo, backups probados, HTTPS, logs auditables, monitoreo de cola y política de retención.

## Documentos fiscales

Separar `TaxDocument` del recibo interno y del documento comercial. Un adaptador de proveedor autorizado conserva request/response originales, hash, estado, errores, XML/CDR/PDF, serie y correlativo, reintentos idempotentes y contingencia. El stock/venta no debe reemitirse por reintento fiscal. El alcance exacto depende de régimen tributario, obligados, punto de emisión y proveedor aprobado por el responsable contable. Validar normativa vigente antes de producción.

## Relación con Socium

Importar servicios/reglas aislados tras quitar Company/Branch/SmartFarma/attendance/wallet como dependencias implícitas. No compartir base de datos ni acoplar ERP al ciclo de despliegue de Socium. La extracción arranca en un commit acordado, con diff de cambios locales revisado.
