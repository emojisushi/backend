<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddCallcenterDeliveryAtToOnlineOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            // Set only when a call centre operator picked the time, so it can be told
            // apart from delivery_at, which is computed from the spot's wait.
            $table->dateTime('callcenter_delivery_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn('callcenter_delivery_at');
        });
    }
}
