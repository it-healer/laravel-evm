<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evm_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('title')->nullable();
            $table->text('password')->nullable();
            $table->text('mnemonic')->nullable();
            $table->text('seed')->nullable();
            $table->string('derivation_path')->default("m/44'/60'/0'/0/{index}");
            $table->dateTime('sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evm_wallets');
    }
};
