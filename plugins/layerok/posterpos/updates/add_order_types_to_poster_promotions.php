<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddOrderTypesToPosterPromotions extends Migration
{
    public function up()
    {
        // Which orders the backend applies the promotion to: all / takeaway /
        // courier. Poster has no such condition, so it is chosen here. Empty means
        // all orders for a gift; a discount cannot be enabled until it is chosen.
        if (!Schema::hasColumn('layerok_posterpos_poster_promotions', 'order_types')) {
            Schema::table('layerok_posterpos_poster_promotions', function (Blueprint $table) {
                $table->string('order_types', 16)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('layerok_posterpos_poster_promotions', 'order_types')) {
            Schema::table('layerok_posterpos_poster_promotions', function (Blueprint $table) {
                $table->dropColumn('order_types');
            });
        }
    }
}
