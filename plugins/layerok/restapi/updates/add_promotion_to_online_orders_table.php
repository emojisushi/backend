<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddPromotionToOnlineOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            // The `promotion` payload sent to Poster, kept so a prepaid order
            // carries the same gifts once the payment comes through.
            $table->text('promotion')->nullable();
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropColumn('promotion');
        });
    }
}
