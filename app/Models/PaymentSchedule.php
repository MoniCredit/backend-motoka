<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentSchedule extends Model
{
     use HasFactory;

 protected $guarded = [];
 protected $with = ['payment_head','gateway','revenue_head'];

    public function payment_head()
{
    return $this->belongsTo(PaymentHead::class,'payment_head_id');
}

    public function gateway()
{
    return $this->belongsTo(Gateway::class,'gateway_id');
}

    public function revenue_head()
{
    return $this->belongsTo(RevenueHead::class,'revenue_head_id');
}

}
