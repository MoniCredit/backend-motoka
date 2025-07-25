<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DriverLicense extends Model {
    use HasFactory;
    protected $table = 'drivers_licenses'; // plural!

    protected $fillable = [
        'user_id', 'license_type', 'license_number', 'full_name', 'phone_number', 'address', 'date_of_birth', 'place_of_birth', 'state_of_origin', 'local_government', 'blood_group', 'height', 'occupation', 'next_of_kin', 'next_of_kin_phone', 'mother_maiden_name', 'license_year', 'passport_photograph', 'status', 'expired_license_upload'
    ];
    public function user()
{
    return $this->belongsTo(User::class, 'user_id', 'userId');
}
}

