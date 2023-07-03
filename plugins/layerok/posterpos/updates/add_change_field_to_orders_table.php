<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * some_upgrade_file.php
 */
class AddChangeFieldToOrdersTable extends Migration
{
    ///
    public function up()
    {
        Schema::table('offline_mall_orders', function (Blueprint $table) {
            $table->string('change')->nullable();
        });
    }

    public function down()
    {
        Schema::table('offline_mall_orders', function (Blueprint $table) {
            $table->dropColumn(['change']);

        });
    }
}


