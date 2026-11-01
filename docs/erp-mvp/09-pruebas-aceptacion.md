# 09. Pruebas y aceptación

Las pruebas de esta fase usan PHPUnit/Laravel Feature tests con SQLite en memoria. Son una primera verificación de flujos locales; no sustituyen las pruebas de concurrencia en MySQL/InnoDB ni la homologación fiscal. El flujo implementado usa 1 caja = 12 unidades y precios/costos en céntimos PEN.

| Escenario obligatorio | Verificación de aceptación |
|---|---|
| Conversiones caja/docena/unidad | Cantidad de stock base coincide; factores no se comparten por defecto; rechazar fracción no permitida y factor cero. |
| Ventas simultáneas | Dos sesiones intentan vender el último stock: exactamente una completa; la otra obtiene conflicto; no hay saldo negativo ni pagos huérfanos. |
| Stock y reservas | Disponible = físico − reservado; reserva no mueve físico; cancelación libera reserva; consumo por venta/despacho la libera una vez. |
| Recepciones parciales | Varias recepciones completan como máximo orden, solo recibido aumenta stock; duplicar clave no duplica; discrepancias requieren resolución. |
| Transferencias | Despacho reduce origen y deja tránsito; recepción aumenta destino; cancelar enviado no borra; sedes ajenas rechazadas. |
| Devolución | No excede cantidad neta vendida; aprobación financiera y revisión física son independientes; solo vendible retorna a stock; reembolso una sola vez. |
| Crédito | Venta respeta límite/permisos; cuenta por cobrar se crea por saldo, no por total doble; vencimiento conserva moneda y cliente. |
| Pagos parciales | Asignación no excede pago/deuda; saldo y vencimiento recalculan; reintento idempotente. |
| Adelantos | Crédito no aplicado permanece a favor; aplicación reduce ambos saldos en una transacción; reembolso es egreso documentado. |
| Cierre de caja | Efectivo esperado coincide con pagos/egresos; conteo por método; diferencias requieren rol y motivo; cierre repetido no duplica ajustes. |
| Anulación | Motivo/permiso requeridos; reversas de stock/pago/CxC ligadas a original; CPE se corrige por nota/flujo fiscal, no borrado. |
| Margen | Utilidad global y por producto/venta se calcula con ventas, devoluciones y costo promedio atribuible; los márgenes con costo incompleto se señalan y no se presentan como exactos; filtros de hoy/semana/mes y fechas personalizadas. |
| Pedido → venta → despacho | Pedido reservado y vendido/despachado parcialmente no provoca doble salida; cancelación libera restante; trazabilidad por línea. |
| Permisos e aislamiento | Cada rol no autorizado recibe rechazo; empresa/sucursal distinta no puede leer o mutar datos; override deja actor/razón. |
| Emisión fiscal | Reintento no crea otro correlativo/documento; guardar acuse/rechazo; contingencia y nota referencian documento original. |

## Resultado local de calidad (2026-10-27)

`php artisan test`: 18 pruebas aprobadas, 271 aserciones. Además de los flujos base, cubre edición y administración independiente de categorías (también sin productos), catálogos centrales de marcas/unidades y protección de unidad base histórica, precios mayoristas, cambios de equivalencia con historial, validación estructural de DNI/RUC, ubicaciones de inventario, conteos físicos, bajas por daño, órdenes y compra directa con varias líneas, gastos de importación prorrateados entre recepciones parciales, compras en moneda extranjera con tasa fechada y conversión de costo de producto y gastos a soles para inventario y conciliación, desglose tributario de factura y su validación contra el total, conciliación de factura en PEN y moneda extranjera con el total de orden/gastos (motivo obligatorio ante diferencias), CxC/CxP y efectivo abierto en reportes, pagos de CxP, traslados, adelantos y aplicación idempotente a ventas, pedidos con varias líneas, egresos financieros, conciliación idempotente de efectivo y filtrado por fecha local de movimientos guardados en UTC.

El estado de cuenta de clientes admite impresión desde el navegador o guardado como PDF. Se identifica expresamente como documento interno; no reemplaza un comprobante fiscal ni un recibo oficial.

**Productos y presentaciones**, **Almacenes e inventario**, **Compras y recepciones**, **Ventas y TPV**, **Clientes y cobranzas**, **Pedidos y despachos**, **Finanzas** y **Gerencia** están **Operativos** para el alcance MVP descrito en `12-estado-modulos.md`. Los datos de identificación del cliente se registran manualmente y se valida su formato, sin consulta al padrón estatal. **Facturación Perú** sigue **Pendiente** hasta implementar y homologar la emisión SUNAT. Los descuentos requieren rol autorizado, y las devoluciones/anulaciones ajustan stock, saldo y caja según corresponda. La suite local usa SQLite en memoria: no acredita concurrencia MySQL ni homologación SUNAT. No se declara el MVP listo para producción.

## Bloqueantes de producción

Antes de abrir al público deben pasar todos los escenarios de conversión, stock/concurrencia, recepción, venta y pagos, crédito/cobranza (si habilitada), cierre de caja, devoluciones/anulación, permisos/aislamiento y emisión fiscal en entorno homologado. Además: conciliación de inventario inicial firmada, conciliación de caja, recuperación de backup ensayada, no hay duplicados de `operation_key` ni deuda/movimientos huérfanos, y pruebas contra versión MySQL exacta de producción. Automatización WhatsApp, forecasting y reportes analíticos pueden esperar; integridad fiscal e inventario no.
