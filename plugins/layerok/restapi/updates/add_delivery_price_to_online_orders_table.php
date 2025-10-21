<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddDeliveryPriceToOnlineOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('delivery_price')->nullable();
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_price');
        });
    }
}
