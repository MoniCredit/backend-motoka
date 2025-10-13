<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverLicensePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'name', 'amount', 'revenue_head_code',
    ];
} 