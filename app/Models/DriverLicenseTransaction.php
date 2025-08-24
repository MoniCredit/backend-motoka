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
        'driver_license_id' => 'string', // Cast to string for UUID
    ];

    /**
     * Get the driver license that owns the transaction.
     */
    public function driverLicense()
    {
        return $this->belongsTo(DriverLicense::class, 'driver_license_id');
    }

    /**
     * Get the user that owns the transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
} 