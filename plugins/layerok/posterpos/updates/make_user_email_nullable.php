<?php

namespace Layerok\PosterPos\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class MakeUserEmailNullable  extends Migration
{
    public function up()
    {
        Schema::table('users', function ($table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('users', function ($table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};


