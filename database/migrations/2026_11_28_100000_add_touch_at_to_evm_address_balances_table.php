<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity per address and network (`evm.touch.per_network`): the address is shared by every
 * network of the wallet, so a touch kept on the address made all of them poll at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evm_address_balances', function (Blueprint $table) {
            $table->timestamp('touch_at')->nullable()->after('scan_state');
        });
    }

    public function down(): void
    {
        Schema::table('evm_address_balances', function (Blueprint $table) {
            $table->dropColumn('touch_at');
        });
    }
};
