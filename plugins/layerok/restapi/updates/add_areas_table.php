<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddAreasTable extends Migration
{
    public function up()
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('spot_id')->nullable()->index();
            $table->foreign('spot_id')->references('id')->on('layerok_posterpos_spots')->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('color')->nullable();
            $table->json('coords');
            $table->timestamps();

        });
    }

    public function down()
    {
        Schema::dropIfExists('areas');
    }

}