<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOTP extends Model
{
    protected $table = 'password_reset_otps';

    protected $fillable = [
        'email',
        'otp',
        'expires_at',
    ];
}
