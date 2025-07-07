<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddAvailabilityFields extends Migration
{
    ///
    public function up()
    {
        Schema::table('layerok_posterpos_spots', function (Blueprint $table) {
            $table->dropColumn(['district_id']);
        });

        Schema::table('layerok_posterpos_districts', function (Blueprint $table) {
            $table->integer('spot_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('layerok_posterpos_spots', function (Blueprint $table) {
            $table->integer('district_id')->nullable();
        });

        Schema::table('layerok_posterpos_districts', function (Blueprint $table) {
            $table->dropColumn(['spot_id']);
        });
    }
}
