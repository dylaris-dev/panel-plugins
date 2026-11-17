<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Illuminate\Support\Facades\DB;

final class PteroStateStore implements StateStore
{
    private const SERVERS = 'dylaris_gateway_servers';

    private const NODES = 'dylaris_gateway_nodes';

    private const DOMAINS = 'dylaris_gateway_domains';

    private const DOMAIN_SYNC = 'dylaris_gateway_domain_sync';

    public function get(int $serverId): ?ServerState
    {
        $row = DB::table(self::SERVERS)->where('server_id', $serverId)->first();

        return $row ? self::toServer($row) : null;
    }

    public function put(ServerState $s): void
    {
        // The first write that sets the flag stamps the time; later writes with the flag still set keep it.
        $since = $s->restartNeeded ? ($this->restartNeededSince($s->serverId) ?? time()) : null;
        DB::table(self::SERVERS)->updateOrInsert(['server_id' => $s->serverId], [
            'restart_needed_at' => $since,
            'mode' => $s->mode->value,
            'label' => $s->label,
            'hoster_domain' => $s->hosterDomain,
            'node_id' => $s->nodeId,
            'port' => $s->port,
            'public_port' => $s->publicPort,
            'proxy_protocol' => $s->proxyProtocol,
            'bedrock' => $s->bedrock,
            'restart_needed' => $s->restartNeeded,
            'last_error' => $s->lastError,
            'retry_after_until' => $s->retryAfterUntil,
            'public_ip' => $s->publicIp,
        ]);
    }

    /** Unix time the restart flag was set, or null when no restart is pending. */
    public function restartNeededSince(int $serverId): ?int
    {
        $at = DB::table(self::SERVERS)->where('server_id', $serverId)->where('restart_needed', true)->value('restart_needed_at');

        return $at === null ? null : (int) $at;
    }

    /** A new change after an earlier one: only a start after THIS change applies it. */
    public function markRestartNeeded(int $serverId, ?int $at = null): void
    {
        DB::table(self::SERVERS)->where('server_id', $serverId)->where('restart_needed', true)->update(['restart_needed_at' => $at ?? time()]);
    }

    /** Only the flag: a whole-row put() from a copy read before the Wings call could undo a change made meanwhile. */
    public function clearRestartNeeded(int $serverId): void
    {
        DB::table(self::SERVERS)->where('server_id', $serverId)->update(['restart_needed' => false]);
    }

    public function delete(int $serverId): void
    {
        DB::table(self::SERVERS)->where('server_id', $serverId)->delete();
    }

    public function all(): array
    {
        return DB::table(self::SERVERS)->orderBy('server_id')->get()->map(self::toServer(...))->all();
    }

    public function node(int $nodeId): ?NodeState
    {
        $row = DB::table(self::NODES)->where('node_id', $nodeId)->first();
        if (!$row) {
            return null;
        }

        return new NodeState(
            (int) $row->node_id,
            $row->link_id,
            $row->region,
            array_map(Mode::from(...), json_decode($row->allowed_modes, true, flags: JSON_THROW_ON_ERROR)),
            $row->target_host,
        );
    }

    public function putNode(NodeState $n): void
    {
        DB::table(self::NODES)->updateOrInsert(['node_id' => $n->nodeId], [
            'link_id' => $n->linkId,
            'region' => $n->region,
            'allowed_modes' => json_encode(array_map(fn (Mode $m) => $m->value, $n->allowedModes), JSON_THROW_ON_ERROR),
            'target_host' => $n->targetHost,
        ]);
    }

    public function domains(): array
    {
        return DB::table(self::DOMAINS)->get()->map(fn (object $r) => new DomainEntry(
            (string) $r->domain,
            (string) $r->source,
            (bool) $r->enabled,
            (string) $r->validation,
            $r->region,
            (string) $r->own_state,
            (string) $r->txt_name,
            (string) $r->txt_value,
            $r->last_seen_at === null ? null : (int) $r->last_seen_at,
            (bool) $r->gone,
        ))->sortBy(fn (DomainEntry $e) => [$e->own() ? 1 : 0, $e->domain])->values()->all();
    }

    public function putDomains(array $domains): void
    {
        DB::transaction(function () use ($domains) {
            DB::table(self::DOMAINS)->whereNotIn('domain', array_map(fn (DomainEntry $e) => $e->domain, $domains))->delete();
            foreach ($domains as $e) {
                DB::table(self::DOMAINS)->updateOrInsert(['domain' => $e->domain], [
                    'source' => $e->source,
                    'enabled' => $e->enabled,
                    'validation' => $e->validation,
                    'region' => $e->region,
                    'own_state' => $e->ownState,
                    'txt_name' => $e->txtName,
                    'txt_value' => $e->txtValue,
                    'last_seen_at' => $e->lastSeenAt,
                    'gone' => $e->gone,
                ]);
            }
        });
    }

    public function domainSync(): DomainSync
    {
        $r = DB::table(self::DOMAIN_SYNC)->where('id', 1)->first();

        return $r === null ? new DomainSync() : new DomainSync(
            $r->synced_at === null ? null : (int) $r->synced_at,
            $r->tried_at === null ? null : (int) $r->tried_at,
            $r->error,
            (bool) $r->new_official_enabled,
        );
    }

    public function putDomainSync(DomainSync $s): void
    {
        DB::table(self::DOMAIN_SYNC)->updateOrInsert(['id' => 1], [
            'synced_at' => $s->syncedAt,
            'tried_at' => $s->triedAt,
            'error' => $s->error,
            'new_official_enabled' => $s->newOfficialEnabled,
        ]);
    }

    private static function toServer(object $row): ServerState
    {
        return new ServerState(
            (int) $row->server_id,
            Mode::from($row->mode),
            $row->label,
            $row->hoster_domain,
            (int) $row->node_id,
            (int) $row->port,
            $row->public_port === null ? null : (int) $row->public_port,
            (bool) $row->proxy_protocol,
            (bool) $row->bedrock,
            (bool) $row->restart_needed,
            $row->last_error,
            $row->retry_after_until === null ? null : (int) $row->retry_after_until,
            $row->public_ip,
        );
    }
}
