<?php
namespace Layerok\PosterPos\Models;

use Model;

class FcmToken extends Model
{
    protected $table = 'fcm_tokens';

    protected $fillable = [
        'fcm_token',
        'user_id',
        'platform',
        'last_used_at',
    ];
}
