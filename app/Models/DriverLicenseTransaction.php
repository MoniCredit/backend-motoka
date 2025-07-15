<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverLicenseTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'amount',
        'driver_license_id',
        'status',
        'reference_code',
        'payment_description',
        'user_id',
        'raw_response',
        'meta_data',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'meta_data' => 'array',
    ];
} 