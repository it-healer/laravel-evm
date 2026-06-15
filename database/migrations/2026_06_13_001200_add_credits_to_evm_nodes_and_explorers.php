<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['evm_nodes', 'evm_explorers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('credits')->default(0)->after('requests_at');
                $table->dateTime('credits_at')->nullable()->after('credits');
            });
        }
    }

    public function down(): void
    {
        foreach (['evm_nodes', 'evm_explorers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['credits', 'credits_at']);
            });
        }
    }
};
