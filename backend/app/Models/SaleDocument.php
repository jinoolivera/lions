<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleDocument extends Model
{
    protected $hidden = ['xml_path', 'cdr_path'];

    protected $fillable = ['sale_id', 'user_id', 'document_type', 'series', 'number', 'issue_date', 'total_cents', 'issued_via', 'environment', 'status', 'xml_path', 'cdr_path', 'sunat_response_code', 'sunat_message', 'sunat_ticket', 'sent_at'];

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
}
