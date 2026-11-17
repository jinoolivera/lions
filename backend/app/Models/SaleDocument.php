<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleDocument extends Model
{
    protected $appends = ['has_pdf'];

    protected $hidden = ['xml_path', 'cdr_path', 'pdf_path'];

    protected $fillable = ['sale_id', 'user_id', 'document_type', 'series', 'number', 'issue_date', 'total_cents', 'issued_via', 'environment', 'status', 'xml_path', 'cdr_path', 'pdf_path', 'sunat_response_code', 'sunat_message', 'sunat_ticket', 'sent_at'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'total_cents' => 'integer', 'sent_at' => 'immutable_datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SaleDocumentAdjustment::class);
    }

    public function getHasPdfAttribute(): bool
    {
        return (bool) $this->pdf_path;
    }
}
