<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->decimal('fee', 36, 18)->nullable()->after('amount');
            $table->unsignedBigInteger('nonce')->nullable()->after('block_number');
            $table->timestamp('dropped_at')->nullable()->after('nonce');
        });
    }

    public function down(): void
    {
        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->dropColumn(['fee', 'nonce', 'dropped_at']);
        });
    }
};
