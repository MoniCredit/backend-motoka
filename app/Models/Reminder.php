<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'remind_at',
        'is_sent',
        'ref_id', // This was missing - needed to link reminder to specific car
    ];

    // Add relationship to Car model
    public function car()
    {
        return $this->belongsTo(Car::class, 'ref_id');
    }
}