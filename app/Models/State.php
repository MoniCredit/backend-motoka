<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
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


    public function geoPoliticalZone(): BelongsTo
    {
        return $this->belongsTo(GeoPoliticalZone::class);
    }


    public function lgas(): HasMany
    {
        return $this->hasMany(Lga::class);
    }
}
