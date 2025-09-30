<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddAddressesTable extends Migration
{
    public function up()
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('name_ua');
            $table->string('name_ru');
            $table->string('lon');
            $table->string('lat');
            $table->string('suburb_ua');
            $table->string('suburb_ru');
            $table->timestamps();

        });
    }

    public function down()
    {
        Schema::dropIfExists('addresses');
    }

}