<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddDistanceToGeocodedAddressesTable extends Migration
{
    public string $tableName = 'layerok_posterpos_geocoded_addresses';

    public function up()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            // How far the chosen candidate sat from the street we know, in metres.
            // Kept on rejected results too, to tune the acceptance radius.
            $table->unsignedInteger('distance_m')->nullable();
        });
    }

    public function down()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->dropColumn('distance_m');
        });
    }
}
