<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddFcmTokensTable extends Migration
{
    public function up()
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable()->index();
            $table->string('fcm_token')->unique();
            $table->string('platform')->default('android');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::drop('fcm_tokens');
    }
}
