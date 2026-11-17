<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleDocumentAdjustment extends Model
{
    protected $appends = ['has_pdf'];

    protected $hidden = ['pdf_path'];

    protected $fillable = ['sale_document_id', 'user_id', 'adjustment_type', 'series', 'number', 'issue_date', 'total_cents', 'issued_via', 'status', 'reason', 'pdf_path'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'total_cents' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SaleDocument::class, 'sale_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getHasPdfAttribute(): bool
    {
        return (bool) $this->pdf_path;
    }
}
