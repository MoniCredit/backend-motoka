<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'state',
        'lga',
        'account_number',
        'bank_name',
        'account_name',
        'profile_image',
        'nin_front_image',
        'nin_back_image',
        'status',
        'notes',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($agent) {
            if (empty($agent->slug)) {
                $agent->slug = Str::random(10);
            }
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function agentPayments(): HasMany
    {
        return $this->hasMany(AgentPayment::class);
    }

    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}