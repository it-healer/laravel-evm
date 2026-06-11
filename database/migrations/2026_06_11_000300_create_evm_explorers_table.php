<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelEvm\Models\EvmNetwork;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_explorers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EvmNetwork::class, 'network_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('driver');
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('base_url')->nullable();
            $table->string('api_key')->nullable();
            $table->string('proxy')->nullable();
            $table->dateTime('sync_at')->nullable();
            $table->json('sync_data')->nullable();
            $table->bigInteger('requests')->default(0);
            $table->date('requests_at')->nullable();
            $table->boolean('worked')->default(true);
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->unique(['network_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_explorers');
    }
};
