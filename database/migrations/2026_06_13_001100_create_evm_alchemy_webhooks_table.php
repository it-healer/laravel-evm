<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmNetwork;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_alchemy_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('webhook_id')->unique();
            $table->text('signing_key');
            $table->unsignedInteger('addresses_count')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('network_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_alchemy_webhooks');
    }
};
