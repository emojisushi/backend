<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class CreateBonusTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('bonus_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedBigInteger('poster_client_id')->nullable();
            $table->unsignedBigInteger('online_order_id')->nullable();
            $table->unsignedBigInteger('incoming_order_id')->nullable()->index();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            // All amounts are in minor units (kopecks), matching Poster's clients.bonus.
            $table->integer('amount');
            $table->integer('delta')->nullable();
            $table->integer('balance_after')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('bonus_transactions');
    }
}
