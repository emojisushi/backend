<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddAccrualBonusesToPosterPromotions extends Migration
{
    public function up()
    {
        // Poster's «Начислять бонусы» of the promotion. Promotion orders earn no
        // points, so a promotion with it on cannot be enabled.
        if (!Schema::hasColumn('layerok_posterpos_poster_promotions', 'accrual_bonuses')) {
            Schema::table('layerok_posterpos_poster_promotions', function (Blueprint $table) {
                $table->boolean('accrual_bonuses')->default(false);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('layerok_posterpos_poster_promotions', 'accrual_bonuses')) {
            Schema::table('layerok_posterpos_poster_promotions', function (Blueprint $table) {
                $table->dropColumn('accrual_bonuses');
            });
        }
    }
}
