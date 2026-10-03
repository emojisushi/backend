<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddGeocodedAddressesTable extends Migration
{
    public string $tableName = 'layerok_posterpos_geocoded_addresses';

    public function up()
    {
        Schema::create($this->tableName, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('street');
            $table->string('house', 64);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // Kept for diagnosing a pin that landed somewhere odd.
            $table->string('display_name', 512)->nullable();
            $table->string('result_class', 64)->nullable();
            // A miss is cached too, so the same address is not looked up on every
            // page load. See RETRY_AFTER_DAYS in GeocodeService.
            $table->boolean('found')->default(false);
            $table->timestamp('looked_up_at')->nullable();
            $table->timestamps();

            $table->unique(['street', 'house']);
        });
    }

    public function down()
    {
        Schema::dropIfExists($this->tableName);
    }
}
