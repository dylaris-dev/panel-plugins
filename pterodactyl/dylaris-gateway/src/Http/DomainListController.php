<?php

namespace Dylaris\GatewayPtero\Http;

use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainList;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\ErrorText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel-wide domain list (admin screen "Gateway domains"), the same as Pelican's page. Mounted under
 * /api/admin/extensions/dylaris-gateway (root admins only, admin throttle).
 */
final class DomainListController
{
    public function show(): JsonResponse
    {
        $problem = null;
        try {
            app(DomainList::class)->ensureFresh();
        } catch (\Throwable $e) {
            $problem = ErrorText::for($e, true);
        }

        return self::ok(['problem' => $problem] + $this->state());
    }

    public function refresh(): JsonResponse
    {
        return $this->run(fn (DomainList $l) => $l->sync(), 'The domain list is up to date.');
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253']]);

        return $this->run(fn (DomainList $l) => $l->addOwn($data['domain']), 'Domain added. Publish its TXT record, then verify it.');
    }

    public function enable(Request $request, string $domain): JsonResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        return $this->run(
            fn (DomainList $l) => $l->setEnabled($domain, $on),
            $on ? 'Users can pick this domain now.' : 'Only admins can pick this domain now. Existing addresses keep working.',
        );
    }

    public function verify(string $domain): JsonResponse
    {
        return $this->run(fn (DomainList $l) => $l->verifyOwn($domain));
    }

    public function remove(string $domain): JsonResponse
    {
        return $this->run(fn (DomainList $l) => $l->remove($domain), 'Domain removed from the list.');
    }

    /** Runs one list change: the new list and the sentence, or the refusal with the list unchanged. */
    private function run(callable $change, ?string $success = null): JsonResponse
    {
        try {
            $result = $change(app(DomainList::class));
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => ErrorText::for($e, true)], 422);
        }

        return self::ok(['problem' => null] + $this->state(), is_string($result) ? $result : $success);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $store = app(StateStore::class);
        $sync = $store->domainSync();

        return [
            'synced_at' => $sync->syncedAt,
            'sync_error' => $sync->error,
            'domains' => array_map(fn (DomainEntry $d) => [
                'domain' => $d->domain,
                'own' => $d->own(),
                'enabled' => $d->enabled,
                'region' => $d->region,
                'status' => $d->status(),
                'usable' => $d->usable(),
                'gone' => $d->gone,
                // The record is the instruction, not a secret: the provider shows it the same way.
                'txt_name' => $d->own() && !$d->gone && $d->ownState !== DomainList::STATE_VERIFIED ? $d->txtName : '',
                'txt_value' => $d->own() && !$d->gone && $d->ownState !== DomainList::STATE_VERIFIED ? $d->txtValue : '',
                'can_verify' => $d->own() && !$d->gone && $d->ownState !== DomainList::STATE_VERIFIED,
                'can_remove' => $d->own() || $d->gone,
            ], $store->domains()),
        ];
    }

    private static function ok(array $data, ?string $message = null): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'message' => $message]);
    }
}
