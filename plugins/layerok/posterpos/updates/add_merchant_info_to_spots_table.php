<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * some_upgrade_file.php
 */
class AddPosterIdToSpotsTable extends Migration
{
    ///
    public function up()
    {
        Schema::table('layerok_posterpos_spots', function (Blueprint $table) {
            $table->string('merchant_account')->nullable();
            $table->string('merchant_secret_key')->nullable();
            $table->string('domain_name')->nullable();
        });
    }

    public function down()
    {
        Schema::table('layerok_posterpos_spots', function (Blueprint $table) {
            $table->dropColumn(['merchant_account']);
            $table->dropColumn(['merchant_secret_key']);
            $table->dropColumn(['domain_name']);
        });
    }
}


