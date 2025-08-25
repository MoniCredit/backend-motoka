<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class DriverLicense extends Model {
    use HasFactory, HasUuids;

    protected $table = 'drivers_licenses'; // plural!

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'license_type', 'license_number', 'full_name', 'phone_number', 'address', 'date_of_birth', 'place_of_birth', 'state_of_origin', 'local_government', 'blood_group', 'height', 'occupation', 'next_of_kin', 'next_of_kin_phone', 'mother_maiden_name', 'license_year', 'passport_photograph', 'status', 'expired_license_upload'
    ];

    /**
     * Boot the model and add event listeners
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($license) {
            if (empty($license->id)) {
                $license->id = (string) Str::uuid();
            }
            if (empty($license->slug)) {
                $license->slug = (string) Str::uuid();
            }
        });
    }
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'userId');
    }
}

