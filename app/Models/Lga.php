<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lga extends Model
{
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
    ];


    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function deliveryFee()
    {
        return $this->hasOne(DeliveryFee::class, 'lga_id', 'id');
    }
}
