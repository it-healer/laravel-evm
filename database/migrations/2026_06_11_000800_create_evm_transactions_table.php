<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmToken;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('txid')->index();
            $table->string('address')->index();
            $table->enum('type', ['in', 'out']);
            $table->timestamp('time_at');
            $table->string('from');
            $table->string('to');
            $table->decimal('amount', 36, 18);
            $table->string('token_address')->default('');
            $table->foreignIdFor(EvmToken::class, 'token_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique(['network_id', 'txid', 'address', 'token_address'], 'evm_transactions_unique');
            $table->index(['network_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_transactions');
    }
};
