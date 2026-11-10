<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * 1.2.24 changed after it had already run on some databases, so they lack the
 * columns added to it later (and may still have the category hours of its first
 * draft). Adds whatever is missing and drops what is no longer used; on a database
 * that ran the final 1.2.24 it does nothing.
 */
class CompletePosterPromotionsColumns extends Migration
{
    private const TABLE = 'layerok_posterpos_poster_promotions';

    public function up()
    {
        $columns = [
            'gift' => fn(Blueprint $table) => $table->text('gift')->nullable(),
            'category_name' => fn(Blueprint $table) => $table->string('category_name')->nullable(),
            'active_from' => fn(Blueprint $table) => $table->string('active_from', 5)->nullable(),
            'active_to' => fn(Blueprint $table) => $table->string('active_to', 5)->nullable(),
            'active_days' => fn(Blueprint $table) => $table->string('active_days')->nullable(),
            'qualifying' => fn(Blueprint $table) => $table->mediumText('qualifying')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn(self::TABLE, $name)) {
                Schema::table(self::TABLE, $add);
            }
        }

        // The first draft of 1.2.24 put ordering hours on categories instead.
        foreach (['available_from', 'available_to'] as $name) {
            if (Schema::hasColumn('offline_mall_categories', $name)) {
                Schema::table('offline_mall_categories', fn(Blueprint $table) => $table->dropColumn($name));
            }
        }
    }

    public function down()
    {
        // Nothing to undo: the columns belong to 1.2.24.
    }
}
