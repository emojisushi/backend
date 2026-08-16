<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class ChangeOnlineOrdersColumnTypes extends Migration
{
    public function up()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->text('products')->nullable()->change();
            $table->text('cart')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->string('products')->nullable()->change();
            $table->string('cart')->nullable()->change();
        });
    }
}