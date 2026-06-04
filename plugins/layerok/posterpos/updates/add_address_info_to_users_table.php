<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * some_upgrade_file.php
 */
class AddAddressInfoToUsersTable extends Migration
{
    ///
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('house_type')->nullable()->after('username');
            $table->string('house')->nullable()->after('house_type');
            $table->string('floor')->nullable()->after('house');
            $table->string('street')->nullable()->after('floor');
            $table->string('apartment')->nullable()->after('street');
            $table->string('entrance')->nullable()->after('apartment');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'house_type',
                'house',
                'floor',
                'street',
                'apartment',
                'entrance',
            ]);
        });
    }
}
