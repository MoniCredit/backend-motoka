<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Payment extends Model
{
    use HasUuids;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function car()
    {
        return $this->belongsTo(Car::class, 'car_id');
    }

    public function paymentSchedule()
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }
}
