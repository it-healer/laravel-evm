<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_address_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmAddress::class, 'address_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->decimal('balance', 36, 18)->nullable();
            $table->json('tokens')->nullable();
            $table->unsignedBigInteger('sync_block_number')->nullable();
            $table->timestamp('sync_at')->nullable();
            $table->timestamps();

            $table->unique(['address_id', 'network_id']);
            $table->index('network_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_address_balances');
    }
};
