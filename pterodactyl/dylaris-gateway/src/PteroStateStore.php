<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Illuminate\Support\Facades\DB;

final class PteroStateStore implements StateStore
{
    private const SERVERS = 'dylaris_gateway_servers';

    private const NODES = 'dylaris_gateway_nodes';

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
