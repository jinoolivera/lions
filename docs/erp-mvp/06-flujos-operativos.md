# 06. Flujos operativos y reglas

## Compra y recepción

Proveedor → orden (compromiso, no stock ni pago) → llegada/recepción parcial (verificar documento, unidades, daño y costo) → movimientos de entrada por cantidad aceptada → CxP según factura/política → pagos aplicados a obligación. Cada recepción tiene clave idempotente. Variación de precio/cantidad exige autorización; faltante permanece abierto o se cierra explícitamente. Gastos de importación se asignan por regla aprobada (valor, cantidad o peso) sin modificar documentos fuente.

## Venta en TPV

Abrir caja → buscar por nombre/SKU/barcode → elegir presentación → servidor resuelve lista/precio/impuesto y valida cantidad → cliente/condiciones → descuento con permiso → pagos (uno o más) y crédito restante → transacción de confirmación bloquea saldos y caja, crea venta/líneas/pagos, consume stock y crea CxC o comprobante fiscal pendiente → impresión/estado fiscal. Un borrador no mueve stock. El servidor calcula totales; completar dos veces devuelve mismo resultado mediante idempotencia.

## Reserva y despacho

Pedido registrado (WhatsApp manual/presencial) → verificar disponible (`physical - reserved`) → reservar en transacción; reserva no reduce físico → preparación y escaneo → despacho parcial consume físico y libera la parte reservada; devolución al inventario solo por reversa documentada tras inspección. Cuando el pedido se convierte en venta, la venta toma la reserva y genera un único movimiento de salida. Cancelar libera el saldo reservado y no crea una entrada física.

## Crédito y adelantos

Validar límite y vencimiento en transacción al crear deuda. Pagos se registran como eventos, y sus asignaciones a facturas no pueden exceder pago ni saldo pendiente. Adelanto es crédito no aplicado, puede asociarse luego a documentos; saldo a favor no es pago negativo. Reembolso y nota de crédito son eventos distintos. Conciliar subledger: deuda inicial + ventas a crédito − asignaciones − notas de crédito = saldo.

## Devoluciones/anulaciones

Solicitar con líneas/cantidades/reason; bloquear venta para comprobar cantidad neta devuelta. Resolver aprobación y pago/refund separado del retorno físico. Producto vendible vuelve a stock solo tras inspección; dañado va a ubicación/estado no vendible. Venta emitida exige documento fiscal de reversión correspondiente; jamás eliminar venta ni solo cambiar el total. Caja registra egreso/reembolso en sesión o cuenta correspondiente.

## Caja y cierre

Sesión tiene operador, caja/sede, base de apertura y pagos/egresos ligados; cierre compara efectivo esperado con conteo por medio. Descuadre requiere motivo y aprobación; no se manipula ingreso original. Una operación tiene una sola clave de enlace entre caja operativa y cualquier resumen financiero.

## Reglas críticas

| Riesgo | Protección |
|---|---|
| Conversión caja/unidad equivocada | Factor por producto/presentación capturado en línea, verificación de enteros o precisión permitida, pruebas de ejemplo. |
| Dos ventas concurrentes | InnoDB, bloqueo del saldo/lote, validación bajo lock, movimiento único e idempotencia; conflicto explícito si no hay stock. |
| Recepción duplicada o parcial | Recepción separada de orden, acumulados, lock de orden/línea y operation key. |
| Transferencia con pérdida/doble saldo | Salida en despacho y entrada en recepción enlazadas; tránsito visible como estado/cuenta; cancelar antes de despachar solamente. |
| Pago/anulación duplicada | Ledger append-only, clave única, reversa relacionada, control de saldo y permisos. |
| Margen incorrecto | Costo congelado en cada venta, costos de importación versionados/distribuidos, impuestos separados de ingreso. |
| Pedido y venta consumen dos veces | Consumo atomizado con conversión y cierre de reserva en la misma transacción; restricción de transición y referencia fuente única. |
| Cierre de caja no representa dinero | Conteo por método, movimientos conciliables, cierre idempotente e inmutable tras aprobación salvo asiento correctivo. |
