# 08. Riesgos y decisiones

## Cinco riesgos principales

1. **Fiscalidad incorrecta o emisión no válida.** PDF propio no equivale a CPE aceptado. Resolver régimen, tipos de operación, obligación/contingencia, series y proveedor autorizado con contador y proveedor antes del hito de venta piloto.
2. **Acoplamiento Socium y copia de estado no liberado.** POS depende de tenant/empresa/sucursal, SmartFarma, asistencia, wallet/categoría y permisos societarios; el árbol inspeccionado tiene cambios sin commit. Congelar commit fuente, revisar diffs y extraer por casos de uso.
3. **Stock/pagos duplicados o perdidos por concurrencia/reintentos.** Mantener ledger inmutable, transacciones, locks, constraints e idempotencia; probar en el mismo motor que producción y hacer reconciliación automática.
4. **Conversión de presentaciones y saldos iniciales erróneos.** Factores distintos por producto y cambios históricos pueden distorsionar costo/stock. Aprobar unidad base, precisión y conteo de apertura por SKU antes de importar.
5. **Alcance de crédito/costos mal definido.** Límite, vencimiento, adelantos, costo puesto en almacén, margen e IGV afectan cobros y rentabilidad. Modelar ledger y políticas antes de reportes; distinguir margen bruto de flujo de caja/utilidad neta.

Otros riesgos: fraude/descuentos, pérdida de red durante emisión, migración de catálogo duplicado, impresión térmica específica, permisos multi-sede, calidad de base de datos de Socium y sobrecarga de operaciones al exigir doble registro.

## Decisiones necesarias (no bloquean el diseño, sí la producción)

- Régimen tributario/RUC, tipos y volúmenes de CPE, series por establecimiento, notas/GRE requeridas y proveedor fiscal/credenciales de homologación.
- Nombre/legalidad y estructura de empresas, sedes, almacenes y ubicaciones; si se permite una ubicación lógica inicial.
- Productos fraccionables y mínima unidad vendible; factores, redondeo, conversiones y tratamiento de mermas.
- Listas de precio, mayorista/minorista, IGV incluido o agregado, niveles de autorización de descuentos.
- Ventas a crédito: límite, plazo, mora, bloqueo, quién aprueba; política de adelantos y aplicación de pagos.
- Costeo promedio o lotes/FIFO según trazabilidad y vencimientos; distribución de flete/seguro/aduana y tasa de cambio.
- Métodos de pago, caja por vendedor/terminal, gastos de caja permitidos, autorizadores de diferencias y anulaciones.
- Canales de pedido/despacho y momento jurídico/comercial de facturación/consumo de inventario.
- Cantidad de usuarios/cajas, volumen de catálogo/movimientos, hardware lector/impresora y despliegue/backup.
- Commit limpio de Socium y cambios locales que se pretende conservar; quién revisa la extracción y cómo importar datos.

No se recomienda congelar cambios locales ni actualizar dependencias de Socium como parte de este análisis.
