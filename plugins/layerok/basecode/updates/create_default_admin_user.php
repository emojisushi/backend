<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Updates\Migration;
use \Backend\Models\User;

class CreateDefaultAdminUser extends Migration
{
    public function up()
    {
        $user = User::where('is_superuser', true)->first();
        if($user) {
            return;
        }
        $password = 'qweasdqweasd';

        User::createDefaultAdmin([
            'first_name' => 'Jonh',
            'last_name' => 'Doe',
            'login' => 'superadmin',
            'email' => 'superadmin@emojisushi.com.ua',
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function down()
    {

    }
}



