<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal transfers (the native coin a contract sent while executing a transaction) share
 * the hash of their transaction with its other rows: a swap is our outgoing call and the coin
 * it returned. The flag joins the unique key so both rows can exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('failed');
        });

        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->unique(['network_id', 'txid', 'address', 'token_address', 'internal'], 'evm_transactions_unique_internal');
        });

        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->dropUnique('evm_transactions_unique');
        });
    }

    public function down(): void
    {
        // The old key cannot hold an internal row next to the other row of its transaction
        \Illuminate\Support\Facades\DB::table('evm_transactions')->where('internal', true)->delete();

        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->unique(['network_id', 'txid', 'address', 'token_address'], 'evm_transactions_unique');
        });

        Schema::table('evm_transactions', function (Blueprint $table) {
            $table->dropUnique('evm_transactions_unique_internal');
            $table->dropColumn('internal');
        });
    }
};
