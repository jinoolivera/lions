# 03. Alcance del MVP

El MVP debe registrar una venta real de productos con unidades de empaque, reservar/consumir existencias sin doble descuento, recibir compras, cobrar contado o crédito, cuadrar caja y producir el comprobante que la empresa esté obligada a emitir. No se define como un conjunto de pantallas.

## P0 — imprescindible para operar

| Capacidad | Criterio de aceptación |
|---|---|
| Productos, códigos, categorías y presentaciones configurables por producto | Una caja de 120 y otra de 96 convierten a stock base por factores propios; se impide cambiar el factor con historial sin conversión/migración controlada. Precio y código por presentación son independientes. |
| Una unidad de medida base canónica por producto | El detalle de venta conserva presentación, factor y cantidad base vendidos al momento de la operación. Cantidades incompatibles con el fraccionamiento se rechazan. |
| Almacén y saldo por producto | Saldo físico y reservado se pueden reconciliar contra movimientos; cada ajuste tiene motivo, actor y referencia. Bloqueo transaccional evita confirmar dos salidas sobre el mismo saldo. |
| Recepción y compra | Orden, recepciones parciales, costos/documento proveedor y obligación pendiente quedan separadas; la recepción aumenta stock una sola vez. |
| Venta y pagos | TPV permite búsqueda, cliente, presentación, precio, descuento autorizado y pagos mixtos. Confirmación atómica guarda líneas/pagos, registra salida de stock, caja/CxC y auditoría o revierte todo. |
| Caja | Apertura con saldo, operaciones ligadas a sesión, arqueo/cierre por método y diferencia justificada/aprobada. Venta nunca se reescribe por cuadrar caja. |
| Clientes y saldo | Venta al crédito registra cuenta por cobrar; pagos parciales y anticipos aplican a documentos o permanecen como crédito no aplicado; saldo se reconcilia por movimientos. |
| Comprobante de venta legal aplicable | Definir régimen/obligación y emitir vía proveedor autorizado o solución permitida; guardar serie/correlativo, payload, respuesta y estado. Una impresión interna no se marca como emisión SUNAT. |
| Acceso y auditoría | Roles separan vendedor, almacenero, aprobador y administrador; descuentos, anulaciones, devoluciones y ajustes quedan atribuidos y protegidos. |
| Flujos críticos probados | Pruebas de integración en MySQL cubren invariantes financieras, inventario, concurrencia e idempotencia antes del piloto. |

## P1 — importante, se puede liberar después del núcleo

- Múltiples almacenes y ubicaciones internas avanzadas (si la empresa puede iniciar con un almacén por sucursal).
- Pedidos manuales, reservas, picking, despacho parcial y constancia de entrega.
- Conteos cíclicos, sugerencias de compra y aprobación de compras por monto.
- Importación de costos (flete, seguro, aduana) y asignación por valor/cantidad/peso, una vez se acuerde la base.
- Alertas, estados de cuenta descargables, reportes de vendedor y márgenes por familia.
- Lotes/vencimientos solo si las categorías comercializadas lo requieren.
- Integración con lector/impresora específica más allá de salida web/impresión de navegador.

## P2 — posterior

- Integración automática WhatsApp, portal de proveedor, forecasting, promociones avanzadas, multi-moneda, contabilidad general completa, consolidación multicompañía y optimización de rutas.
- Automatización de conciliación bancaria y analítica con almacén de datos.

## Criterios de salida del MVP

Un usuario autorizado puede: cargar el catálogo; recibir de forma parcial una compra; ver el saldo correcto; vender en distintas presentaciones; cobrar contado o dejar saldo a crédito; emitir el comprobante requerido; cerrar caja; registrar devolución/anulación con reversas compensatorias; y obtener saldo de stock, CxC, caja y margen desde transacciones auditables. Debe repetirse el mismo comando con igual clave de idempotencia sin duplicar efectos y rechazarse con payload distinto. No puede finalizarse la compra/venta ante saldo insuficiente o autorización ausente.
