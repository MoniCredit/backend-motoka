<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RevenueHead extends Model
{
      use HasFactory;

 protected $guarded = [];
 protected $with = ['bank','gateway'];

    public function bank()
{
    return $this->belongsTo(Bank::class,'bank_id');
}

    public function gateway()
{
    return $this->belongsTo(Gateway::class,'gateway_id');
}
}
