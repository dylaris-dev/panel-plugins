<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The domains a node offers for gateway addresses (JSON list); null = never saved, every hoster domain.
        // The egg list lives in the extension settings, so it needs no column here.
        Schema::table('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->json('domains')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->dropColumn('domains');
        });
    }
};
