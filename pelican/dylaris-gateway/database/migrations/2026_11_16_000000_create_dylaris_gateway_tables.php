<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row. The API key lives here, encrypted, because Pelican's plugin settings write .env in plain text.
        Schema::create('dylaris_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider_url');
            $table->text('api_key')->nullable();
            $table->timestamps();
        });

        // No foreign keys: a row must outlive its server or node so the reconcile can clean up after it.
        Schema::create('dylaris_gateway_servers', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->primary();
            $table->string('mode', 16);
            $table->string('label');
            $table->string('hoster_domain');
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('port');
            $table->unsignedInteger('public_port')->nullable();
            $table->boolean('proxy_protocol')->default(false);
            $table->boolean('bedrock')->default(false);
            $table->boolean('restart_needed')->default(false);
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('retry_after_until')->nullable();
        });

        Schema::create('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->unsignedInteger('node_id')->primary();
            $table->string('link_id')->nullable();
            $table->string('region')->nullable();
            $table->json('allowed_modes');
            $table->string('target_host')->default('172.18.0.1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dylaris_gateway_nodes');
        Schema::dropIfExists('dylaris_gateway_servers');
        Schema::dropIfExists('dylaris_gateway_settings');
    }
};
