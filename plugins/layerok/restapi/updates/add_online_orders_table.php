<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddOnlineOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('online_orders', function (Blueprint $table) {
            $table->id();
            $table->integer('status')->nullable();
            $table->string('online_payment_id')->nullable();
            $table->string('poster_id')->nullable();    
            $table->string('products')->nullable();
            $table->string('cart')->nullable();
            $table->string('total')->nullable();
            $table->string('phone')->nullable();
            $table->string('comment')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('address')->nullable();
            $table->integer('service_mode')->nullable();
            $table->unsignedInteger('spot_id')->nullable()->index();
            $table->foreign('spot_id')->references('id')->on('layerok_posterpos_spots')->onDelete('set null');
            $table->timestamps();

        });
    }

    public function down()
    {
        Schema::dropIfExists('online_orders');
    }

}