<!doctype html>
<html lang="es"><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;color:#242424;max-width:600px;margin:auto;padding:24px">
<div style="border-bottom:3px solid #9b7830;padding-bottom:16px"><img src="{{ asset('images/lions-logo.jpeg') }}" alt="U.LIONS" width="64" height="64" style="vertical-align:middle;border-radius:8px"> <strong style="font-size:22px">U.LIONS IMPORT</strong></div>
<h1 style="font-size:22px">Guía de Remisión</h1>
<p>Adjuntamos el PDF de la guía <strong>{{ $guide->series }}-{{ $guide->number }}</strong> vinculada a la venta {{ $sale->sale_number }}.</p>
<p style="color:#777;font-size:12px">Documento emitido fuera de Lions y registrado para su consulta. Verifica su estado en el sistema de emisión correspondiente.</p>
</body></html>
