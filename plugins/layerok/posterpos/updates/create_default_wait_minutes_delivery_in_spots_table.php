<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * some_upgrade_file.php
 */
class AddDefaultWaitMinutesDeliveryToSpotsTable extends Migration
{
    ///
    public function up()
    {
        Schema::table('layerok_posterpos_spots', function (Blueprint $table) {
            $table->integer('default_wait_minutes_delivery')->nullable();
        });
    }

    public function down()
    {
        Schema::table('layerok_posterpos_spots', function (Blueprint $table) {
            $table->dropColumn(['default_wait_minutes_delivery']);
        });
    }
}


