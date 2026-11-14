<!doctype html>
<html lang="es"><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;color:#242424;max-width:600px;margin:auto;padding:24px">
<div style="border-bottom:3px solid #9b7830;padding-bottom:16px"><img src="{{ asset('images/lions-logo.jpeg') }}" alt="U.LIONS" width="64" height="64" style="vertical-align:middle;border-radius:8px"> <strong style="font-size:22px">U.LIONS IMPORT</strong></div>
<h1 style="font-size:22px">Ticket de venta</h1>
<p><strong>{{ $sale->sale_number }}</strong><br>{{ $sale->sold_at?->format('d/m/Y H:i') }}<br>{{ $sale->customer_name ?: 'Venta al público' }}</p>
<table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;border-bottom:1px solid #ddd;padding:8px">Producto</th><th style="text-align:right;border-bottom:1px solid #ddd;padding:8px">Importe</th></tr></thead><tbody>
@foreach($sale->lines as $line)
<tr><td style="padding:8px;border-bottom:1px solid #eee">{{ $line->product_name_snapshot }} · {{ $line->presentation_name_snapshot }}<br><small>{{ $line->quantity }} × S/ {{ number_format($line->unit_price_cents / 100, 2) }}</small></td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right">S/ {{ number_format($line->line_total_cents / 100, 2) }}</td></tr>
@endforeach
</tbody></table>
<p style="font-size:20px;text-align:right"><strong>Total: S/ {{ number_format($sale->total_cents / 100, 2) }}</strong></p>
<p style="color:#777;font-size:12px">Documento interno de venta. Gracias por su compra.</p>
</body></html>
