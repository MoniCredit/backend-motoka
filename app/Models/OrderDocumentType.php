<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDocumentType extends Model
{
    protected $fillable = [
        'order_type',
        'document_name',
        'document_key',
        'is_required',
        'sort_order',
    ];

    public function scopeForOrderType($query, $orderType)
    {
        return $query->where('order_type', $orderType)->orderBy('sort_order');
    }
}
