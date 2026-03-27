<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddDefaultDeliveryMinutesToAreaTable extends Migration
{
    public function up()
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->integer('default_delivery_minutes')->nullable();
        });
    }

    public function down()
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('default_delivery_minutes');
        });
    }
}
