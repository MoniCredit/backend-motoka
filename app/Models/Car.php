<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Car extends Model
{
    use HasFactory, SoftDeletes;

    
    protected $guarded = [];


    protected $dates = [
        'date_issued',
        'expiry_date',
        'deleted_at',
    ];

    protected $casts = [
        'document_images' => 'array',
        'expiry_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'userId');
    }
}
