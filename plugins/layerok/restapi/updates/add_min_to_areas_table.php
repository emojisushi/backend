<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddAreasTable extends Migration
{
    public function up()
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->integer('min')->nullable();
        });
    }

    public function down()
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('min');
        });
    }
}
