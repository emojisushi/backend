<?php

namespace Layerok\PosterPos\Models;

use OFFLINE\Mall\Models\User as UserBase;

class User extends UserBase
{
    public $fillable = [
        'name',
        'surname',
        'login',
        'username',
        'email',
        'password',
        'password_confirmation',
        'created_ip_address',
        'last_ip_address',
        'phone'
    ];

    public function isCallCenterAdmin(): bool {
        return $this->email === 'callcenter@emojisushi.com.ua' || $this->email === 'callcenter2@emojisushi.com.ua';
    }
}
