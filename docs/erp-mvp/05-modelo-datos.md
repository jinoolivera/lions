# 05. Modelo de datos mínimo

Propuesta lógica (no migraciones). PK `BIGINT`, FK explícitas, `created_at`, actor/auditoría; importes `BIGINT` en centavos y cantidades/factores `DECIMAL` precisos. Tablas con prefijo funcional según convención del proyecto.

## Catálogo y abastecimiento

- `companies` (si aplica), `users`, `roles`, `permissions`, `company_user`/rol asignado.
- `categories`, `brands`, `products` (SKU único por empresa, barcode global o por emisor según regla, base unit, fraccionable, mínimo, estado), `product_presentations` (`product_id`, nombre, factor a unidad base, barcode, purchasable/sellable, precisión), `price_lists`, `product_prices` (presentation, lista/segmento, importe, vigencia).
- `suppliers`, `purchase_orders`, `purchase_order_lines` (presentación/factor/precio/impuesto capturados), `receipts`, `receipt_lines` (orden/line, cantidad aceptada/rechazada, costo, lote), `supplier_documents`, `supplier_charges` y opcional `landed_cost_allocations`.
- `supplier_payables` o subledger: puede derivarse de factura/recepción bajo política; `supplier_payments` y `supplier_payment_allocations` permiten abono parcial y crédito anticipado.

## Inventario

- `warehouses`, `warehouse_locations` (zona/rack/bin; ubicación lógica por defecto si operación simple), `stock_balances` únicos por ubicación/producto/lote con `physical_qty`, `reserved_qty`, costo.
- `inventory_movements`: producto, ubicación, lote, unidad base, cantidad con signo, tipo, operación y línea fuente, costo, actor, fecha, `reversal_of_id`, `operation_key` único; inmutable.
- `stock_reservations` y `reservation_lines` con origen pedido/venta, estado y cantidades; `stock_transfers`, líneas y eventos/recepción destino.
- `stock_counts`, líneas contadas y `adjustment_approvals` para conservar conteos y resolución.

## Ventas, caja, cobranza y despacho

- `customers` (tipo, DNI/RUC validable, datos fiscales/domicilio, límite, estado); `customer_addresses`.
- `orders`, líneas, eventos de estado; pedidos pueden existir sin venta final. `sales`, `sale_lines` guardan presentación, factor, unidad base, precios, descuentos, impuestos, costo capturado, vendedor y relación a pedido.
- `payments`, `payment_allocations` (venta/CxC y monto); `customer_ledger_entries` o subledger derivable con reglas explícitas; adelantos como crédito no aplicado, no un saldo editable.
- `cash_registers`, `cash_sessions`, `cash_movements`, `cash_counts`; cada pago/reembolso/egreso se liga a sesión y método de pago. Evitar escribir el mismo efecto en dos ledgers sin clave de enlace única.
- `shipments`, líneas (cantidad preparada/despachada/entregada), dirección congelada, responsable, eventos, relación a pedido/venta y reserva.
- `expenses`/`expense_categories` para operación y costos adicionales.

## Tributación y documentos

- `tax_documents`: tipo, venta origen, serie, correlativo, estado, proveedor, request/response, hash, XML/CDR/PDF, errores y timestamps de envío/respuesta.
- `tax_document_lines` conserva base imponible, código/afectación y montos congelados. `tax_document_events` para auditoría/reintentos. Notas de crédito/débito referencian documento/venta original; no alteran la venta original.

## Relaciones e integridad

`product` 1:N presentations/prices; compra 1:N líneas y recepciones; recepción 1:N líneas que generan movimientos; venta 1:N líneas/pagos; cliente 1:N ventas/anticipos; pedido 1:N líneas/reservas/despachos y 0:1 venta (según política); caja 1:N movimientos; producto/ubicación/lote 1:1 saldo; movimiento N:1 producto/ubicación y fuente polimórfica controlada o FKs por dominio.

Índices/constraints: unique SKU por empresa, barcode según regla, factor positivo y base inmutable con historial, claves compuestas de saldo, `operation_key`, tipo+serie+correlativo fiscal único por empresa, documento proveedor por proveedor/tipo/serie/número, saldo no negativo validado bajo lock, `reserved_qty <= physical_qty`, allocation no supera importe pagable ni saldo de línea. FKs restrictivas para registros financieros/inventario; borrado lógico solo en catálogo sin historial. Aplicar reversas en vez de cascadas para hechos contabilizados.

**Presentaciones no duplican existencia:** se convierte todo a unidad base con factor capturado; 2 cajas × 120 = 240 unidades y 3 docenas × 12 = 36 unidades. Las fracciones solo pasan si son compatibles con resolución de la unidad base. Cambiar un factor ya usado requiere un registro de vigencia y nunca recalcula documentos históricos.
