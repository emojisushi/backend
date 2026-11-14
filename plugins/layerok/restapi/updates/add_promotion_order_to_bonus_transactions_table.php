<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddPromotionOrderToBonusTransactionsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('bonus_transactions', 'promotion_order')) {
            Schema::table('bonus_transactions', function (Blueprint $table) {
                // The order got a Poster promotion (gift or discount): no points are
                // earned on it, so the accrual Poster withholds is not granted either.
                $table->boolean('promotion_order')->default(false);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('bonus_transactions', 'promotion_order')) {
            Schema::table('bonus_transactions', function (Blueprint $table) {
                $table->dropColumn('promotion_order');
            });
        }
    }
}
