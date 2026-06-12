<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_wallet_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmWallet::class, 'wallet_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['wallet_id', 'network_id']);
            $table->index('network_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_wallet_networks');
    }
};