<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Cashier\Billable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'tele','email','username','password_hash','email_verified',
        'refresh_token_hash','refresh_token_expires_at','last_login_at',
        'email_verification_token','password_reset_token','password_reset_expires_at',
        'is_deleted',
    ];

    protected $hidden = [
        'password_hash',
        'refresh_token_hash',
        'email_verification_token',
        'password_reset_token',
    ];

    // Auth::attempt が参照するパスワードを password_hash にする
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }
    
    public function setPasswordAttribute($value): void
    {
        // Laravelが "password" に書こうとしたら、実際は password_hash に保存する
        $this->attributes['password_hash'] = Hash::make((string)$value);
    }

    use Billable;
}
