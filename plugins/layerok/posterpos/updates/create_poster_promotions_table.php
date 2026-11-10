<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class CreatePosterPromotionsTable extends Migration
{
    public function up()
    {
        // Poster's loyalty promotions (clients.getPromotions), copied in by the
        // import button. Only the ones the backend knows how to apply can be enabled.
        Schema::create('layerok_posterpos_poster_promotions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('poster_account_id')->unsigned();
            $table->integer('poster_promotion_id')->unsigned();
            $table->string('name');
            $table->mediumText('params')->nullable();
            $table->dateTime('date_start')->nullable();
            $table->dateTime('date_end')->nullable();
            $table->boolean('auto_apply')->default(false);
            // Set by the import when the promotion is no longer returned by Poster.
            $table->boolean('removed_in_poster')->default(false);
            $table->boolean('enabled')->default(false);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['poster_account_id', 'poster_promotion_id'], 'poster_promotions_account_promo_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('layerok_posterpos_poster_promotions');
    }
}
