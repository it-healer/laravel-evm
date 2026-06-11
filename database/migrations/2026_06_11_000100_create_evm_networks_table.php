<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_networks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chain_id')->unique();
            $table->string('name')->unique();
            $table->string('title')->nullable();
            $table->string('currency_symbol');
            $table->unsignedTinyInteger('currency_decimals')->default(18);
            $table->string('explorer_url')->nullable();
            $table->unsignedTinyInteger('tx_type')->nullable();
            $table->unsignedInteger('confirmations_target')->default(12);
            $table->unsignedInteger('lag_blocks')->nullable();
            $table->unsignedInteger('block_time')->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('sync_at')->nullable();
            $table->json('sync_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_networks');
    }
};
