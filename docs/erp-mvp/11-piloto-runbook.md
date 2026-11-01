# Operación del piloto Lions ERP

## Alcance y responsabilidad

El MVP registra catálogo, existencias, compras/recepciones, ventas, caja, cartera y pedidos/entregas. Los documentos emitidos dentro del sistema son documentos operativos; no sustituyen comprobantes electrónicos SUNAT ni un sistema contable. Conciliar las cifras del ERP con documentos fuente durante el piloto.

## Preparación de un entorno

1. Provisionar una VM Linux administrada por Lions, dominio propio y certificado HTTPS. Usar MySQL/InnoDB administrado o respaldado y restringir su acceso a la aplicación.
2. Instalar PHP 8.3+, Composer 2, Node.js y npm según la versión fijada por el proyecto. Crear `.env` a partir de `.env.example`; usar `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS, `SESSION_SECURE_COOKIE=true` y credenciales únicas. No guardar `.env` en Git ni compartirlo por mensajería.
3. Instalar dependencias y compilar: `composer install --no-dev --prefer-dist --optimize-autoloader`, `npm ci`, `npm run build`.
4. En ventana de mantenimiento, ejecutar `php artisan migrate --force`, `php artisan optimize` y `php artisan storage:link` si corresponde. Crear el primer administrador con `php artisan lions:user:create` mediante una terminal segura.
5. Ejecutar `php artisan about`, comprobar `/up`, iniciar sesión por HTTPS y confirmar que la aplicación carga recursos estáticos. Mantener `APP_DEBUG=false`.

## Respaldo y restauración

Hacer copia diaria cifrada de la base y conservarla fuera del servidor de aplicación, con retención acordada por Lions. Guardar por separado los archivos subidos si se habilitan. Limitar acceso y probar una restauración antes de usar datos reales.

Ejemplo MySQL (solicita contraseña de forma interactiva):

```sh
umask 077
mysqldump --single-transaction --routines --triggers --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE" | gzip > "lions-$(date +%F-%H%M).sql.gz"
```

Restaurar en una base aislada, no encima de producción: `gunzip -c lions-AAAA-MM-DD-HHMM.sql.gz | mysql --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE"`. Después validar conteos de ventas, movimientos y saldos; comparar una muestra con los documentos fuente. Documentar quién hizo la restauración y el resultado.

## Rutina de cierre y conciliación

- Por turno: revisar ventas, pagos por medio, cajas abiertas y diferencias entre efectivo esperado y contado. Registrar y explicar la diferencia antes de cerrar el turno.
- Diario: revisar ventas y cobranzas contra vouchers/depósitos; revisar pedidos pendientes, recepciones y facturas de proveedor.
- Semanal: revisar productos bajo mínimo y hacer conteo físico selectivo; investigar diferencias antes de ajustes.
- En Reportes, filtrar fechas y almacén. La valorización de stock es estimada con el costo promedio disponible; los artículos sin costo completo se cuentan y se señalan. Los saldos mostrados para ventas del periodo corresponden a dichas ventas, no a toda la cartera histórica.

## Cambios y recuperación

Antes de cada actualización, crear respaldo y confirmar que es legible. Publicar una versión identificable del código, ejecutar migraciones en ventana controlada y verificar `/up`, acceso, reporte, una venta de prueba autorizada, movimiento de stock y conciliación. Si falla, detener escrituras y restaurar el último respaldo validado o volver a la versión anterior conforme al procedimiento del administrador; registrar las operaciones hechas después del respaldo para su conciliación manual.

## Criterios para terminar el piloto

- Usuarios y permisos operativos definidos por Lions; credenciales individuales y no compartidas.
- Catálogo, unidades base, presentaciones, costos y mínimos revisados.
- Saldo inicial conciliado contra conteo físico por almacén.
- Venta, pago, cierre de caja, pedido, entrega parcial, cobranza y recepción comprobados por responsables.
- Diferencias explicadas, respaldo y restauración ensayados, y responsables de soporte identificados.

No activar operación real hasta que el responsable de Lions confirme la conciliación inicial, reglas de crédito, responsables de caja y protocolo de contingencia.
