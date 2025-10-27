<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddSmsConfirmationsTable extends Migration
{
    public function up()
    {
        Schema::create('sms_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('last_code');
            $table->boolean('confirmed')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sms_confirmations');
    }
}