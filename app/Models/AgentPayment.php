<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AgentPayment extends Model
{
    protected $fillable = [
        'slug',
        'agent_id',
        'order_id',
        'amount',
        'commission_rate',
        'commission_amount',
        'status',
        'paid_at',
        'notes',
        'transfer_reference',
        'paystack_transfer_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($agentPayment) {
            if (empty($agentPayment->slug)) {
                $agentPayment->slug = Str::random(10);
            }
            
            // Calculate commission amount
            if (empty($agentPayment->commission_amount)) {
                $agentPayment->commission_amount = ($agentPayment->amount * $agentPayment->commission_rate) / 100;
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
