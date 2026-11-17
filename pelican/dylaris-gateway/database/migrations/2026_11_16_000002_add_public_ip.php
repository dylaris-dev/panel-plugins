<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The IP of the public allocation released in Gateway only: on a multi-IP node the port alone is not the address.
        Schema::table('dylaris_gateway_servers', function (Blueprint $table) {
            $table->string('public_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dylaris_gateway_servers', function (Blueprint $table) {
            $table->dropColumn('public_ip');
        });
    }
};
