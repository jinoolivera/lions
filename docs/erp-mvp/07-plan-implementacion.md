# 07. Plan de implementación

Estimaciones en días-persona para un desarrollador con revisión funcional; no son calendario. Dependen de validación de reglas y selección de proveedor fiscal. Base estimada para núcleo: 70–115 DP, incluyendo las adaptaciones Socium elegidas, nueva experiencia React y pruebas; no incluye limpieza masiva de datos ni integraciones no acordadas. Si se decide no reutilizar, aumentar el rango.

| Fase | Entrega vertical verificable | Dependencias | Esfuerzo |
|---|---|---|---:|
| 0. Cerrar auditoría/arquitectura | Commit Socium aprobado, límites de dominio, empresa/sucursal/almacén iniciales, volumen, fiscalidad y aceptación firmados; esqueleto API/UI y entorno | Acceso a responsable operativo/contable | 4–7 DP |
| 1. Catálogo, presentaciones, almacén y permisos | CRUD validado; equivalencias y precios; saldo inicial trazable; consulta de stock con disponible/reservado | Decisiones unidad base, sede y roles | 9–15 DP |
| 2. Compras y recepción | Proveedor → orden → recepción total/parcial → entrada de stock → obligación y costo visible | Fase 1, impuestos/documentos de compra | 10–16 DP |
| 3. Venta, caja y fiscalización mínima | TPV por presentación → pago mixto/contado → consumo de stock → caja → emisión aplicable → cierre | Fases 1–2; proveedor fiscal y régimen definidos | 16–25 DP |
| 4. Clientes, crédito y cobranza | Maestro, límite, venta crédito, pago parcial, adelanto y estado de cuenta | Fase 3; política de crédito | 9–15 DP |
| 5. Pedidos, reservas, picking y entrega | Ingreso manual → reserva → preparación → despachos parciales → venta/entrega sin doble consumo | Fases 1 y 3 | 9–15 DP |
| 6. Reportes, hardening y despliegue piloto | KPIs basados en ledger, backups/restore, monitoreo, capacitación y conciliación inicial | Flujos anteriores; dueño de operación | 10–18 DP |

## Camino crítico

Definición fiscal/forma de emisión y política de precio/impuesto → modelo de producto/presentación → saldo inicial y compras/recepción → venta + caja + comprobante → cobranza. Stock, pagos y comprobante deben integrarse antes del piloto; no liberar solo la UI del TPV.

## Paralelismo seguro

Después de acordar contratos de API y modelo: (a) React de catálogo/TPV puede avanzar con mocks; (b) adaptador fiscal y homologación con proveedor puede avanzar paralelo al núcleo; (c) importación/depuración de catálogo y clientes puede prepararse aparte; (d) reportes y QA pueden definir consultas/fixtures mientras se cierran servicios. No paralelizar dos implementaciones que escriban el mismo saldo o subledger.

## Hitos de aceptación

Cada fase termina con demostración usando datos de ejemplo y tests automatizados del proceso completo; el piloto inicia con un almacén/sede controlado, conteo físico inicial validado, caja y catálogo conciliados, y procedimiento de contingencia fiscal documentado.
