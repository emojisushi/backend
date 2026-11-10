<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddGiftSnapshotAndWindowToPosterPromotions extends Migration
{
    public function up()
    {
        Schema::table('layerok_posterpos_poster_promotions', function (Blueprint $table) {
            // Gift products are usually kept out of the menu in Poster, so they may
            // never be synced here. The import keeps what the apps and receipts need.
            $table->text('gift')->nullable();
            $table->string('category_name')->nullable();
            // Our own time of day for the promotion, "HH:MM" in the account's
            // timezone; both empty means no limit beyond Poster's own periods.
            $table->string('active_from', 5)->nullable();
            $table->string('active_to', 5)->nullable();
        });
    }

    public function down()
    {
        Schema::table('layerok_posterpos_poster_promotions', function (Blueprint $table) {
            $table->dropColumn(['gift', 'category_name', 'active_from', 'active_to']);
        });
    }
}
