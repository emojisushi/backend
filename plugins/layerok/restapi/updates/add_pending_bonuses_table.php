<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;
use Layerok\PosterPos\Models\User;

class AddPendingBonusesTable extends Migration
{
    public function up()
    {
        Schema::create('pending_bonuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedInteger('user_id');
            $table->integer('use_bonus_amount');
            $table->integer('receive_bonus_amount');
            $table->boolean('pending')->default(true);
            $table->timestamps();

            //$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pending_bonuses');
    }

}