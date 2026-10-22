<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network slug of the Alchemy URL (https://{slug}.g.alchemy.com/v2/KEY) of a network the
 * built-in list does not know: found by AlchemySlugFinder or entered by the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evm_networks', function (Blueprint $table) {
            $table->string('alchemy_slug', 64)->nullable()->after('explorer_url');
        });
    }

    public function down(): void
    {
        Schema::table('evm_networks', function (Blueprint $table) {
            $table->dropColumn('alchemy_slug');
        });
    }
};
