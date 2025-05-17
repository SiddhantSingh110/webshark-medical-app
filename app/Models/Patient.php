<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Patient extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'gender',
        'dob',
        'height',
        'weight',
        'health_flags',
        'profile_photo',
        'blood_group',
    ];

    protected $hidden = [
        'password',
    ];
}
