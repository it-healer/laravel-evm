<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmWallet::class, 'wallet_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('address');
            $table->string('title')->nullable();
            $table->boolean('watch_only')->nullable();
            $table->text('private_key')->nullable();
            $table->integer('index')->nullable();
            $table->timestamp('touch_at')->nullable();
            $table->timestamp('sync_at')->nullable();
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->unique(['wallet_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_addresses');
    }
};
