<?php
namespace Layerok\PosterPos\Models;

use Model;

class SmsConfirmation extends Model
{
    protected $table = 'sms_confirmations';

    protected $fillable = [
        'id',
        'phone',
        'last_code',
        'confirmed',
    ];

}
