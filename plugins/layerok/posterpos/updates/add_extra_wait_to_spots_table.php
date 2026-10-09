<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddExtraWaitToSpotsTable extends Migration
{
    public string $tableName = 'layerok_posterpos_spots';

    public function up()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->boolean('extra_wait_enabled')->default(false);
            $table->integer('extra_wait_minutes')->default(20);
        });
    }

    public function down()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->dropColumn(['extra_wait_enabled', 'extra_wait_minutes']);
        });
    }
}
