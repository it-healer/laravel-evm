<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-webhook Alchemy Notify account: the auth token the webhook was created with
     * (so every operation targets the right account) and an opaque reference to the
     * account record managing it. Null falls back to the configured default token.
     */
    public function up(): void
    {
        Schema::table('evm_alchemy_webhooks', function (Blueprint $table) {
            if (! Schema::hasColumn('evm_alchemy_webhooks', 'auth_token')) {
                $table->text('auth_token')->nullable()->after('signing_key');
            }
            if (! Schema::hasColumn('evm_alchemy_webhooks', 'account_ref')) {
                $table->string('account_ref')->nullable()->after('auth_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('evm_alchemy_webhooks', function (Blueprint $table) {
            $table->dropColumn(['auth_token', 'account_ref']);
        });
    }
};
