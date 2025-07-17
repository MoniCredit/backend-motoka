<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Car extends Model
{
    use HasFactory, SoftDeletes;

    
    protected $fillable = [
        'user_id',
        'name_of_owner',
        'phone_number',
        'address',
        'vehicle_make',
        'vehicle_model',
        'registration_status',
        'car_type',
        'chasis_no',
        'engine_no',
        'vehicle_year',
        'vehicle_color',
        'registration_no',
        'date_issued',
        'expiry_date',
        'document_images',
        'status',
        // Plate fields
        'plate_number',
        'type',
        'preferred_name',
        'business_type',
        'cac_document',
        'letterhead',
        'means_of_identification',
        // New plate number fields
        'state_of_origin',
        'local_government',
        'blood_group',
        'height',
        'occupation',
        'next_of_kin',
        'next_of_kin_phone',
        'mother_maiden_name',
        'license_years',
    ];


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
