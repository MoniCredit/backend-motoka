<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'state_id',
        'lga_id',
        'fee',
    ];
} 