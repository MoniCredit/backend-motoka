<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasUuids;

    /**
     * The primary key is a UUID string.
     */
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'raw_response' => 'array',
    ];

    /**
     * Boot the model and add event listeners
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            // ensure id and slug exist
            if (empty($payment->id)) {
                $payment->id = (string) Str::uuid();
            }
            if (empty($payment->slug)) {
                $payment->slug = (string) Str::uuid();
            }
        });
    }

    public function car()
    {
        return $this->belongsTo(Car::class, 'car_id');
    }

    public function paymentSchedule()
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }
}
