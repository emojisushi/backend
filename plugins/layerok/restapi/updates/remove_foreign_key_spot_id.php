<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class RemoveForeignKeySpotId extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropForeign(["spot_id"]);
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->foreign('spot_id')->references('id')->on('layerok_posterpos_spots')->onDelete('set null');
        });
    }

}