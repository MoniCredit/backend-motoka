<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'user_id',
        'car_id',
        'payment_id',
        'agent_id',
        'order_type',
        'status',
        'amount',
        'delivery_address',
        'delivery_contact',
        'state',
        'lga',
        'notes',
        'processed_at',
        'processed_by',
        'completed_at',
        'documents_sent_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($order) {
            if (empty($order->slug)) {
                $order->slug = Str::random(10);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function agentPayments(): HasMany
    {
        return $this->hasMany(AgentPayment::class);
    }

    public function orderDocuments(): HasMany
    {
        return $this->hasMany(OrderDocument::class, 'order_slug', 'slug');
    }
}