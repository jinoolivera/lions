# 10. Preguntas clave para cerrar alcance

Estas respuestas son necesarias antes de aprobar el diseño final de datos y empezar la implementación de flujos reales:

1. ¿Cuál es el repositorio y commit de Socium que se considera fuente autorizada? ¿Los cambios locales de la copia actual deben integrarse o quedan fuera?
2. ¿Cuál será la razón social/RUC y régimen tributario? ¿Qué comprobantes electrónicos y guías deben emitirse desde el primer día y qué proveedor/contador los operará?
3. ¿Cuántas sedes, almacenes y cajas operarán en el lanzamiento? ¿Se necesita ubicación interna por rack/bin desde el comienzo?
4. Para cada familia: unidad base, si se vende fraccionada y factores por caja/media caja/ciento/docena. ¿Cómo se redondea y se trata merma?
5. ¿Qué precios existen (mayorista, minorista, por cliente)? ¿Precios incluyen IGV? ¿Quién autoriza descuentos y anulaciones?
6. ¿Habrá venta a crédito desde el lanzamiento? Definir límites, plazos, aprobación, bloqueo por mora y aplicación de adelantos.
7. ¿Qué métodos de pago/canales hay y cómo se organiza caja: por terminal, por vendedor o compartida? ¿Qué diferencia exige aprobación?
8. ¿Se requiere lote/vencimiento para productos específicos? ¿Qué política de costo se adopta y cómo se reparten flete/seguro/aduana?
9. ¿Pedido, despacho y venta/facturación ocurren en qué orden operativo? ¿Se permitirá entrega parcial y backorder?
10. ¿Qué datos iniciales existen (productos, clientes, proveedores, saldos, inventario físico) y quién los depurará/validará?
11. ¿Qué impresoras/lectores, cantidad de usuarios y volumen diario se esperan? ¿Cuál es el entorno de despliegue y RPO/RTO deseado?

El próximo paso técnico es crear una rama/copia de trabajo limpia de Socium en el commit que el responsable declare fuente, ejecutar y revisar sus pruebas POS sobre MySQL aislado, y acordar un mapa explícito de dependencias a desacoplar antes de copiar una sola migración.
