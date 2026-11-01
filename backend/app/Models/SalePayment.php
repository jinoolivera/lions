<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SalePayment extends Model { protected $fillable = ['sale_id','customer_id','cash_session_id','user_id','kind','method','amount_cents','reference','operation_key','payload_hash']; protected function casts(): array { return ['amount_cents'=>'integer']; } public function sale(): BelongsTo { return $this->belongsTo(Sale::class); } }
