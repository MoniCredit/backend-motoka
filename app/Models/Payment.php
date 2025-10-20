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
        'gateway_response' => 'array',
        'payment_schedule_id' => 'array', // Cast to array for bulk payments
        'meta_data' => 'array',
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

        static::updated(function ($payment) {
            // Check if payment status changed to completed
            if ($payment->isDirty('status') && $payment->status === 'completed') {
                // Only create notification if it wasn't already completed
                if ($payment->getOriginal('status') !== 'completed') {
                    \Log::info('Payment model event triggered', [
                        'payment_id' => $payment->id,
                        'old_status' => $payment->getOriginal('status'),
                        'new_status' => $payment->status
                    ]);
                    \App\Services\PaymentService::handlePaymentCompletion($payment);
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function car()
    {
        return $this->belongsTo(Car::class, 'car_id');
    }

    public function driverLicense()
    {
        return $this->belongsTo(DriverLicense::class, 'driver_license_id');
    }

    public function paymentSchedule()
    {
        // Handle both single and bulk payments
        if (is_array($this->payment_schedule_id) && count($this->payment_schedule_id) === 1) {
            return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id')->whereIn('id', $this->payment_schedule_id);
        } elseif (is_array($this->payment_schedule_id) && count($this->payment_schedule_id) > 1) {
            // For bulk payments, return the first schedule as primary
            return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id')->where('id', $this->payment_schedule_id[0]);
        } elseif (is_array($this->payment_schedule_id) && count($this->payment_schedule_id) === 0) {
            // For payments without schedules (like driver license payments), return a relationship that will be null
            return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id')->where('id', -1);
        } else {
            // Single payment
            return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
        }
    }

    public function paymentSchedules()
    {
        // Return all payment schedules for bulk payments
        if (is_array($this->payment_schedule_id) && count($this->payment_schedule_id) > 0) {
            return $this->hasMany(PaymentSchedule::class, 'id')->whereIn('id', $this->payment_schedule_id);
        } elseif (is_array($this->payment_schedule_id) && count($this->payment_schedule_id) === 0) {
            // For payments without schedules (like driver license payments), return empty collection
            return $this->hasMany(PaymentSchedule::class, 'id')->where('id', -1); // This will return empty collection
        } else {
            // Single payment - return as collection
            return $this->hasMany(PaymentSchedule::class, 'id')->where('id', $this->payment_schedule_id);
        }
    }
}
