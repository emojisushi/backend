<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddDeliveryAtToOnlineOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            // Absolute time the order is due, as sent to Poster. Kept so a prepaid
            // order reports the same time after payment that it promised at checkout.
            $table->dateTime('delivery_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_at');
        });
    }
}
