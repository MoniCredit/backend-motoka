<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDocument extends Model
{
    protected $fillable = [
        'order_slug',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'uploaded_by',
        'status',
        'admin_notes',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_slug', 'slug');
    }
}
