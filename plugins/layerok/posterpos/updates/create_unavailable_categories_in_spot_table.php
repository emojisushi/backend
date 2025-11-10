<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * some_upgrade_file.php
 */
class CreateUnavailableCategoriesInSpotTable extends Migration
{
    ///
    public function up()
    {
        Schema::create('layerok_posterpos_unavailable_categories_in_spot', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('category_id')->unsigned();
            $table->integer('spot_id')->unsigned();

        });
    }

    public function down()
    {
        Schema::drop('layerok_posterpos_unavailable_categories_in_spot');
    }
}


