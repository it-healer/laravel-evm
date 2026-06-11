<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmNetwork;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('address');
            $table->string('name');
            $table->string('symbol');
            $table->unsignedInteger('decimals');
            $table->string('logo_uri')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['network_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_tokens');
    }
};
