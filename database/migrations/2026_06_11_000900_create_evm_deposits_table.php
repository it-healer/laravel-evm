<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmToken;
use ItHealer\LaravelEvm\Models\EvmWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(EvmWallet::class, 'wallet_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(EvmAddress::class, 'address_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(EvmToken::class, 'token_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('txid');
            $table->decimal('amount', 36, 18);
            $table->unsignedBigInteger('block_number')->nullable();
            $table->integer('confirmations')->default(0);
            $table->timestamp('time_at');
            $table->timestamps();

            $table->unique(['network_id', 'address_id', 'txid', 'token_id'], 'evm_deposits_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_deposits');
    }
};
