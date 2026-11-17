<?php

use Dylaris\GatewayPanel\DomainList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.2.0: one panel-wide domain list replaces the per-node lists (dylaris_gateway_nodes.domains). The node lists are
 * migrated by DomainList::fromNodeLists() and the column is dropped. The same migration ships with the Pterodactyl
 * extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dylaris_gateway_domains', function (Blueprint $table) {
            $table->string('domain', 253)->primary();
            $table->string('source', 16);
            $table->boolean('enabled');
            $table->string('validation', 32)->default('');
            $table->string('region', 64)->nullable();
            $table->string('own_state', 32)->default('');
            $table->string('txt_name', 255)->default('');
            $table->string('txt_value', 255)->default('');
            $table->unsignedBigInteger('last_seen_at')->nullable();
            $table->boolean('gone')->default(false);
        });
        Schema::create('dylaris_gateway_domain_sync', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('synced_at')->nullable();
            $table->unsignedBigInteger('tried_at')->nullable();
            $table->text('error')->nullable();
            $table->boolean('new_official_enabled')->default(true);
        });

        self::loadCore();
        $lists = [];
        // Gateway nodes only: a saved list counts, and so does a connected node that never saved one (it offered every
        // official domain). A panel node never set up for the gateway had no list to mean anything.
        foreach (DB::table('dylaris_gateway_nodes')->get(['link_id', 'domains']) as $row) {
            if ($row->domains !== null) {
                $lists[] = json_decode($row->domains, true, flags: JSON_THROW_ON_ERROR);
            } elseif (($row->link_id ?? '') !== '') {
                $lists[] = null;
            }
        }
        [$entries, $newOfficialEnabled] = DomainList::fromNodeLists($lists);
        foreach ($entries as $e) {
            DB::table('dylaris_gateway_domains')->insert([
                'domain' => $e->domain,
                'source' => $e->source,
                'enabled' => $e->enabled,
                'validation' => $e->validation,
            ]);
        }
        // No synced_at: the first use syncs, which fills in regions and own-domain states.
        DB::table('dylaris_gateway_domain_sync')->insert(['id' => 1, 'new_official_enabled' => $newOfficialEnabled]);

        Schema::table('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->dropColumn('domains');
        });
    }

    /** Every node gets the list as it stands: switched off reads as admins only, gone domains are left out. */
    public function down(): void
    {
        Schema::table('dylaris_gateway_nodes', function (Blueprint $table) {
            $table->json('domains')->nullable();
        });
        $list = DB::table('dylaris_gateway_domains')->where('gone', false)->orderBy('source')->orderBy('domain')->get()
            ->map(fn ($r) => ['domain' => $r->domain, 'custom' => $r->source === 'own', 'admin_only' => !$r->enabled, 'validation' => $r->validation])
            ->all();
        if ($list !== []) {
            DB::table('dylaris_gateway_nodes')->update(['domains' => json_encode($list, JSON_THROW_ON_ERROR)]);
        }
        Schema::dropIfExists('dylaris_gateway_domain_sync');
        Schema::dropIfExists('dylaris_gateway_domains');
    }

    /** The panel may run migrations before the plugin registered its core autoloader. */
    private static function loadCore(): void
    {
        foreach (['DomainEntry', 'DomainList'] as $class) {
            if (!class_exists("Dylaris\\GatewayPanel\\{$class}")) {
                require_once dirname(__DIR__, 2) . "/core-src/{$class}.php";
            }
        }
    }
};
