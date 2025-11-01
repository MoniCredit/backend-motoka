<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\DriverLicense;
use App\Models\Plate;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'two_factor_enabled' => 'boolean',
        'two_factor_email_expires_at' => 'datetime',
    ];

    public function userType() {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }
    public function driversLicense()
    {
        return $this->hasOne(DriverLicense::class, 'user_id', 'userId');

    }
    /**
     * Multiple driver's licenses relationship (for admin panel)
     */
    public function driverLicenses()
    {
        return $this->hasMany(DriverLicense::class, 'user_id', 'userId');
    }


    public function plate()
    {
        return $this->hasOne(Plate::class, 'user_id', 'userId');
    }

    /**
     * Multiple plates relationship
     */
    public function plates()
    {
        return $this->hasMany(Plate::class, 'user_id', 'userId');
    }

    /**
     * Cars relationship
     */
    public function cars()
    {
        return $this->hasMany(Car::class, 'user_id', 'userId');
    }

    /**
     * Orders relationship
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id', 'userId');
    }

    /**
     * Payments relationship
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'user_id', 'userId');
    }


}
