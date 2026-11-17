<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

use Dylaris\GatewayPanel\Provider\CustomDomain;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\Provider\Region;
use Dylaris\GatewayPanel\Provider\Regions;

/**
 * The panel-wide list of address domains: the provider's official domains, which arrive through the API on their own,
 * and the account's own domains, added and verified here. Each has a switch (enabled = users may pick it, disabled =
 * admins only), and a node offers only the domains of its own region. Toggling or removing a domain never changes an
 * existing address; it only decides what can be picked for a new address or a rename.
 *
 * No extends or implements on purpose: the migrations load this file on its own (fromNodeLists()).
 */
final class DomainList
{
    /** A list older than this is synced again at the point it is needed. */
    public const MAX_AGE = 3600;

    /** After a failed sync, the next on-demand attempt waits this long; the scheduled sync and Refresh do not. */
    public const RETRY_AFTER = 300;

    public const STATE_VERIFIED = 'verified';

    public const BUSY = 'The domain list is being updated, try again in a moment.';

    /**
     * @param (\Closure(callable, bool): mixed)|null $exclusive runs a list change alone, under the panel's cache lock:
     *        ($f, true) waits for the lock (throwing ModeRefused(BUSY) when it does not come), ($f, false) returns null
     *        at once while someone else holds it. Null runs everything directly (tests, a single process).
     */
    public function __construct(
        private readonly ProviderClient $provider,
        private readonly StateStore $store,
        private readonly ?\Closure $exclusive = null,
    ) {
    }

    /** One list change at a time: a sync and an admin's add, verify or remove never interleave their reads and writes. */
    private function exclusive(callable $f, bool $wait = true): mixed
    {
        return $this->exclusive === null ? $f() : ($this->exclusive)($f, $wait);
    }

    /**
     * Reads the provider's official and own domains and merges them into the stored list. A failure keeps the stored
     * list, records why, and is re-thrown.
     *
     * @return DomainEntry[]
     */
    public function sync(?int $now = null): array
    {
        return $this->exclusive(fn () => $this->syncNow($now ?? time()));
    }

    /** @return DomainEntry[] */
    private function syncNow(int $now): array
    {
        $meta = $this->store->domainSync();
        try {
            $regions = $this->provider->regions();
            $own = $this->provider->customDomains();
        } catch (ProviderException $e) {
            // A Retry-After longer than RETRY_AFTER moves the recorded attempt forward, so the on-demand sync waits it out.
            $tried = $now + max(0, ($e->retryAfter ?? 0) - self::RETRY_AFTER);
            $this->store->putDomainSync(new DomainSync($meta->syncedAt, $tried, $e->userMessage, $meta->newOfficialEnabled));
            throw $e;
        }
        $merged = self::merge($this->store->domains(), $regions, $own, $now, $meta->newOfficialEnabled);
        // Column-scoped: a stored row keeps whatever enabled says at write time, so a switch flipped while this sync
        // ran is never undone by the sync's older read. merge()'s enabled only matters for a new row.
        $this->store->mergeDomains($merged);
        $merged = $this->store->domains();
        $this->store->putDomainSync(new DomainSync($now, $now, null, true));
        return $merged;
    }

    /**
     * Syncs when the list is empty or older than MAX_AGE (a fresh install, a panel that was down), unless an on-demand
     * attempt failed less than RETRY_AFTER ago (or the provider's longer Retry-After), the provider asked the
     * reconcile to wait, or another request is syncing right now (single-flight: that one does it, this one uses the
     * stored list). A failure keeps the stored list.
     */
    public function ensureFresh(?int $now = null): void
    {
        $now ??= time();
        $meta = $this->store->domainSync();
        $stale = $meta->syncedAt === null || $meta->syncedAt < $now - self::MAX_AGE || $this->store->domains() === [];
        if (!$stale || ($meta->triedAt !== null && $meta->triedAt > $now - self::RETRY_AFTER)) {
            return;
        }
        if (Reconciler::busyUntil($this->store->all()) > $now) {
            return;
        }
        try {
            $this->exclusive(fn () => $this->syncNow($now), false);
        } catch (ProviderException) {
            // Recorded by syncNow(); the stored list stays in use.
        }
    }

    /**
     * What the actor may pick on a node of $region.
     *
     * @return AllowedDomain[]
     */
    public function choices(?string $region, bool $actorIsAdmin): array
    {
        $this->ensureFresh();
        return self::offered($this->store->domains(), $region, $actorIsAdmin);
    }

    /**
     * The rule behind choices(): usable domains of the node's region or of no region; users get the enabled ones only.
     *
     * @param DomainEntry[] $entries
     * @return AllowedDomain[]
     */
    public static function offered(array $entries, ?string $region, bool $actorIsAdmin): array
    {
        $out = [];
        foreach ($entries as $e) {
            if ($e->usable() && ($e->enabled || $actorIsAdmin) && ($e->region === null || $e->region === $region)) {
                $out[] = $e->choice();
            }
        }
        return $out;
    }

    /**
     * The region an official domain belongs to: the one whose wildcard is "*." + the domain. Null when none matches.
     *
     * @param Region[] $regions
     */
    public static function regionOf(string $domain, array $regions): ?string
    {
        foreach ($regions as $r) {
            if (strtolower($r->wildcard) === '*.' . strtolower($domain) && $r->region !== '') {
                return $r->region;
            }
        }
        return null;
    }

    /**
     * The stored list after a sync. Listed domains are refreshed and marked seen; a new official domain arrives with
     * $newOfficialEnabled, a new own domain (added at the provider directly) arrives disabled. A stored domain the
     * provider no longer lists stays, marked gone. The admin's switch is never changed.
     *
     * @param DomainEntry[] $stored
     * @param CustomDomain[] $own
     * @return DomainEntry[] official domains first, each group by name
     */
    public static function merge(array $stored, Regions $regions, array $own, int $now, bool $newOfficialEnabled = true): array
    {
        $old = [];
        foreach ($stored as $e) {
            $old[$e->domain] = $e;
        }
        $out = [];
        foreach ($regions->hosterDomains as $h) {
            $d = strtolower($h->domain);
            if ($d === '') {
                continue;
            }
            $enabled = isset($old[$d]) ? $old[$d]->enabled : $newOfficialEnabled;
            // A known region is sticky: Core lists a region only while one of its edges is registered, so a region
            // missing from one answer must not make its domain "every region".
            $region = self::regionOf($d, $regions->items) ?? ($old[$d] ?? null)?->region;
            $out[$d] = new DomainEntry($d, DomainEntry::OFFICIAL, $enabled, $h->validation, $region, lastSeenAt: $now);
        }
        foreach ($own as $c) {
            if ($c->domain === '' || isset($out[$c->domain])) {
                continue;
            }
            $prev = $old[$c->domain] ?? null;
            $out[$c->domain] = new DomainEntry(
                $c->domain,
                DomainEntry::OWN,
                $prev?->enabled ?? false,
                ownState: $c->state,
                // The list carries the record once a token exists; keep the one stored from the add otherwise.
                txtName: $c->txtName !== '' ? $c->txtName : ($prev->txtName ?? ''),
                txtValue: $c->txtValue !== '' ? $c->txtValue : ($prev->txtValue ?? ''),
                lastSeenAt: $now,
            );
        }
        foreach ($old as $d => $e) {
            $out[$d] ??= $e->with(gone: true);
        }
        return self::sorted(array_values($out));
    }

    /**
     * The migration from per-node lists (0.1.x). A domain ticked on any node is kept, as official or own; one marked
     * admins only on any node starts disabled, always: the explicit mark wins. A gateway node that never saved a list
     * offered every official domain to its users, so if one exists, official domains new to the list arrive enabled;
     * otherwise the official domains no node ticked start disabled once the first sync brings them in.
     *
     * Works on the stored JSON (domain, custom, admin_only, validation), so a migration can call it without the rest.
     *
     * @param list<list<array<string,mixed>>|null> $lists one per gateway node (a node with a kit, or one that saved a
     *        list); null = that node never saved a list. Panel nodes never set up for the gateway are not passed.
     * @return array{0: DomainEntry[], 1: bool} the entries, and DomainSync::$newOfficialEnabled
     */
    public static function fromNodeLists(array $lists): array
    {
        $anyUnsaved = $lists === [] || in_array(null, $lists, true);
        $out = [];
        foreach ($lists as $list) {
            foreach ($list ?? [] as $a) {
                $d = strtolower(trim((string) ($a['domain'] ?? '')));
                if ($d === '') {
                    continue;
                }
                $own = (bool) ($a['custom'] ?? false);
                $enabled = ($out[$d]->enabled ?? true) && !((bool) ($a['admin_only'] ?? false));
                $out[$d] = new DomainEntry($d, $own ? DomainEntry::OWN : DomainEntry::OFFICIAL, $enabled, $own ? '' : (string) ($a['validation'] ?? ''));
            }
        }
        return [self::sorted(array_values($out)), $anyUnsaved];
    }

    /**
     * The sync command's one line, the same on both panels.
     *
     * @param DomainEntry[] $entries
     */
    public static function summary(array $entries): string
    {
        $n = ['official' => 0, 'own' => 0, 'gone' => 0, 'off' => 0];
        foreach ($entries as $e) {
            $n[$e->gone ? 'gone' : $e->source]++;
            $n['off'] += $e->enabled || $e->gone ? 0 : 1;
        }
        return "Domains: {$n['official']} official, {$n['own']} own, {$n['gone']} no longer offered; {$n['off']} admins only.";
    }

    public function setEnabled(string $domain, bool $on): void
    {
        $this->store->setDomainEnabled($this->entry($domain)->domain, $on);
    }

    /**
     * Claims an own domain at the provider and lists it, enabled: it can be picked once verified. Returns the entry
     * with the TXT record to publish.
     */
    public function addOwn(string $domain): DomainEntry
    {
        return $this->exclusive(fn () => $this->addOwnNow($domain));
    }

    private function addOwnNow(string $domain): DomainEntry
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $domain)) {
            throw new ModeRefused('Enter a domain name like play.example.com.');
        }
        foreach ($this->store->domains() as $e) {
            if ($e->domain === $domain && !$e->gone) {
                throw new ModeRefused("{$domain} is already in the list.");
            }
        }
        $c = $this->provider->addCustomDomain($domain);
        $entry = new DomainEntry($domain, DomainEntry::OWN, true, ownState: $c->state, txtName: $c->txtName, txtValue: $c->txtValue, lastSeenAt: time());
        $this->store->mergeDomains([$entry]);
        // A gone row of the same name keeps its old switch in the merge; an own domain the admin adds is on.
        $this->store->setDomainEnabled($domain, true);
        return $entry;
    }

    /** Asks the provider to check the own domain's TXT record now. Returns the sentence for the admin. */
    public function verifyOwn(string $domain): string
    {
        return $this->exclusive(fn () => $this->verifyOwnNow($domain));
    }

    private function verifyOwnNow(string $domain): string
    {
        $e = $this->entry($domain);
        if (!$e->own() || $e->gone) {
            throw new ModeRefused('Only a listed own domain can be verified.');
        }
        if ($e->ownState === self::STATE_VERIFIED) {
            return "{$e->domain} is verified.";
        }
        if ($e->txtValue === '') {
            $c = $this->provider->customDomainTxtToken($e->domain);
            $this->store->mergeDomains([$e->with(txtName: $c->txtName, txtValue: $c->txtValue)]);
            throw new ModeRefused("Publish the TXT record {$c->txtName} with the value {$c->txtValue} first, then verify.");
        }
        try {
            $this->provider->verifyCustomDomain($e->domain);
        } catch (ProviderException $x) {
            // Verified elsewhere meanwhile (the DYLARIS panel, Core's own re-check): the provider refuses a second
            // verify. Its list says what is true; anything else is the refusal to show.
            if ($x->status < 400 || $x->status >= 500 || $x->status === 429 || !$this->verifiedAtProvider($e->domain)) {
                throw $x;
            }
        }
        $this->store->mergeDomains([$e->with(ownState: self::STATE_VERIFIED, lastSeenAt: time())]);
        return "{$e->domain} is verified. Users can pick it once it is enabled.";
    }

    /**
     * Removes a domain from the list. An own domain is released at the provider too (refused there while a route
     * uses it). An official domain can only be removed once the provider no longer lists it.
     */
    public function remove(string $domain): void
    {
        $this->exclusive(fn () => $this->removeNow($domain));
    }

    private function verifiedAtProvider(string $domain): bool
    {
        foreach ($this->provider->customDomains() as $c) {
            if ($c->domain === $domain) {
                return $c->verified();
            }
        }
        return false;
    }

    private function removeNow(string $domain): void
    {
        $e = $this->entry($domain);
        if (!$e->own() && !$e->gone) {
            throw new ModeRefused('An official domain stays in the list while the provider offers it. Switch it off instead.');
        }
        if ($e->own() && !$e->gone) {
            try {
                $this->provider->deleteCustomDomain($e->domain);
            } catch (ProviderException $x) {
                if ($x->status !== 404) {
                    throw $x;
                }
            }
        }
        $this->store->deleteDomain($e->domain);
    }

    private function entry(string $domain): DomainEntry
    {
        $domain = strtolower(trim($domain));
        foreach ($this->store->domains() as $e) {
            if ($e->domain === $domain) {
                return $e;
            }
        }
        throw new ModeRefused('That domain is not in the list.');
    }

    /**
     * @param DomainEntry[] $entries
     * @return DomainEntry[]
     */
    private static function sorted(array $entries): array
    {
        usort($entries, fn (DomainEntry $a, DomainEntry $b) => [$a->own(), $a->domain] <=> [$b->own(), $b->domain]);
        return $entries;
    }
}
