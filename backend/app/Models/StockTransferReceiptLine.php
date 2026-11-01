<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferReceiptLine extends Model
{
    protected $fillable = ['stock_transfer_receipt_id', 'stock_transfer_line_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'decimal:6']; }
    public function transferLine(): BelongsTo { return $this->belongsTo(StockTransferLine::class, 'stock_transfer_line_id'); }
}
