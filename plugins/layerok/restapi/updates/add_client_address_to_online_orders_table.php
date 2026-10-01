<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddClientAddressToOnlineOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            // Structured delivery address (address1/address2/comment/lat/lng) as sent
            // to Poster. Kept so a prepaid order can rebuild it after payment.
            $table->text('client_address')->nullable();
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn('client_address');
        });
    }
}
