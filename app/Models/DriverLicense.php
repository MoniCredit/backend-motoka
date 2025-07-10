<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DriverLicense extends Model {
    use HasFactory;
    protected $table = 'drivers_licenses'; // plural!

    protected $guarded = [];
    public function user()
{
    return $this->belongsTo(User::class, 'user_id', 'userId');
}
}

