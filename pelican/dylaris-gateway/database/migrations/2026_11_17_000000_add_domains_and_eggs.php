<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The domains a node offers for gateway addresses (JSON list); null = never saved, every hoster domain.
        Schema::table('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->json('domains')->nullable();
        });
        // The egg ids that may use the gateway (JSON list); null = never saved, the Java Minecraft eggs.
        Schema::table('dylaris_gateway_settings', function (Blueprint $table) {
            $table->json('eggs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->dropColumn('domains');
        });
        Schema::table('dylaris_gateway_settings', function (Blueprint $table) {
            $table->dropColumn('eggs');
        });
    }
};
