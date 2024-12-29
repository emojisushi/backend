<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * some_upgrade_file.php
 */
class CreateHiddenCategoriesInCityTable extends Migration
{
    ///
    public function up()
    {
        Schema::create('layerok_posterpos_hidden_categories_in_city', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('category_id')->unsigned();
            $table->integer('city_id')->unsigned();

        });
    }

    public function down()
    {
        Schema::drop('layerok_posterpos_hidden_categories_in_city');
    }
}


