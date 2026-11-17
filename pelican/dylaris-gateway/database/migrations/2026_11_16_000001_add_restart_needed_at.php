<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When restart_needed was set: a server that started after this has applied the change.
        Schema::table('dylaris_gateway_servers', function (Blueprint $table) {
            $table->unsignedBigInteger('restart_needed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dylaris_gateway_servers', function (Blueprint $table) {
            $table->dropColumn('restart_needed_at');
        });
    }
};
