<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 14px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #17191c; }
.center { text-align: center; }.muted { color: #666; }.brand { font-size: 14px; font-weight: bold; }
.logo { width: 42px; height: 42px; }.rule { border-top: 1px dashed #777; margin: 10px 0; }
table { width: 100%; border-collapse: collapse; } td { padding: 3px 0; vertical-align: top; }
.right { text-align: right; }.total { font-size: 13px; font-weight: bold; }.item { padding: 5px 0; border-bottom: 1px solid #ddd; }
</style></head><body>
<div class="center">
<img class="logo" src="data:image/jpeg;base64,{{ $logo }}">
<div class="brand">{{ $company?->trade_name ?: 'U.LIONS IMPORT' }}</div>
<div>{{ $sale->warehouse?->branch?->name ?: $sale->warehouse?->name }}</div>
@if($sale->warehouse?->branch?->address)
<div>{{ $sale->warehouse->branch->address }}</div>
@endif
@if($company?->ruc)
<div>RUC {{ $company->ruc }}</div>
@endif
@if($company?->legal_name)
<div>{{ $company->legal_name }}</div>
@endif
@if($company?->fiscal_address)
<div class="muted">Domicilio fiscal: {{ $company->fiscal_address }}</div>
@endif
<div class="muted">Ticket interno de venta</div>
</div>
<div class="rule"></div>
<table><tr><td>Venta</td><td class="right">{{ $sale->sale_number }}</td></tr><tr><td>Fecha</td><td class="right">{{ $sale->sold_at?->format('d/m/Y H:i') }}</td></tr><tr><td>Cliente</td><td class="right">{{ $sale->customer_name ?: 'Venta al público' }}</td></tr></table>
<div class="rule"></div>
@foreach($sale->lines as $line)
<div class="item"><strong>{{ $line->product_name_snapshot }}</strong><br><span class="muted">{{ $line->presentation_name_snapshot }} · {{ $line->quantity }} × S/ {{ number_format($line->unit_price_cents / 100, 2) }}</span><span style="float:right">S/ {{ number_format($line->line_total_cents / 100, 2) }}</span>@if($line->discount_cents > 0)<br><span class="muted">Descuento S/ {{ number_format($line->discount_cents / 100, 2) }}</span>@endif</div>
@endforeach
<div class="rule"></div>
<table><tr class="total"><td>Total</td><td class="right">S/ {{ number_format($sale->total_cents / 100, 2) }}</td></tr><tr><td>Pagado</td><td class="right">S/ {{ number_format($sale->paid_cents / 100, 2) }}</td></tr>@if($sale->paid_cents < $sale->total_cents)<tr><td>Saldo pendiente</td><td class="right">S/ {{ number_format(($sale->total_cents - $sale->paid_cents) / 100, 2) }}</td></tr>@endif</table>
<div class="rule"></div>
<div class="center muted">Documento interno de control. No es factura ni boleta electrónica SUNAT.</div><p class="center">Gracias por su compra</p>
</body></html>
