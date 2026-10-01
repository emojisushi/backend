<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddCouriersTable extends Migration
{
    public string $tableName = 'layerok_posterpos_couriers';

    public function up()
    {
        Schema::create($this->tableName, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('login')->nullable();
            $table->string('password')->nullable();
            // Poster employee this courier is. Deliveries carry only the employee
            // id, and Poster exposes no way to list couriers specifically, so the
            // mapping is made here by hand.
            $table->unsignedBigInteger('poster_user_id')->nullable()->index();
            $table->decimal('rate_per_km', 8, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists($this->tableName);
    }
}
