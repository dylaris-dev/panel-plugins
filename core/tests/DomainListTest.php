<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainList;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\ModeRefused;
use Dylaris\GatewayPanel\Provider\CustomDomain;
use Dylaris\GatewayPanel\Provider\HosterDomain;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\Provider\Region;
use Dylaris\GatewayPanel\Provider\Regions;
use Dylaris\GatewayPanel\Testing\InMemoryStateStore;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DomainListTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FakeProvider $provider;
    private InMemoryStateStore $store;
    private DomainList $list;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
        $this->provider->regions = [
            'hosterDomains' => [['domain' => 'eu.dylaris.com', 'validation' => 'letters'], ['domain' => 'us.dylaris.com', 'validation' => 'dns']],
            'items' => [
                ['region' => 'eu-central', 'wildcard' => '*.eu.dylaris.com', 'online' => true],
                ['region' => 'us-east', 'wildcard' => '*.us.dylaris.com', 'online' => false],
            ],
        ];
        $this->store = new InMemoryStateStore();
        $this->list = new DomainList($this->provider->client(), $this->store);
    }

    /** @param DomainEntry[] $entries */
    private static function names(array $entries): array
    {
        return array_map(fn ($e) => $e->domain, $entries);
    }

    public function testTheRegionComesFromTheWildcardThatIsTheDomainWithAStar(): void
    {
        $regions = [new Region('eu-central', '*.eu.dylaris.com', true), new Region('e2e', '*.play.e2e.test', false)];

        $this->assertSame('eu-central', DomainList::regionOf('eu.dylaris.com', $regions));
        $this->assertSame('e2e', DomainList::regionOf('PLAY.e2e.test', $regions));
        $this->assertNull(DomainList::regionOf('dylaris.com', $regions), 'a parent of the wildcard is no match');
        $this->assertNull(DomainList::regionOf('x.eu.dylaris.com', $regions), 'a name under the wildcard is no match');
        $this->assertNull(DomainList::regionOf('eu.dylaris.com', []));
    }

    public function testANodeOffersItsRegionAndTheRegionlessDomainsAndUsersOnlyTheEnabledOnes(): void
    {
        $entries = [
            new DomainEntry('eu.dylaris.com', DomainEntry::OFFICIAL, true, 'letters', 'eu-central'),
            new DomainEntry('us.dylaris.com', DomainEntry::OFFICIAL, true, 'dns', 'us-east'),
            new DomainEntry('vip.dylaris.com', DomainEntry::OFFICIAL, false, 'dns', 'eu-central'),
            new DomainEntry('any.example.net', DomainEntry::OFFICIAL, true, 'dns'),
            new DomainEntry('play.example.com', DomainEntry::OWN, true, ownState: 'verified'),
            new DomainEntry('pending.example.com', DomainEntry::OWN, true, ownState: 'pending'),
            new DomainEntry('old.dylaris.com', DomainEntry::OFFICIAL, true, 'dns', 'eu-central', gone: true),
        ];

        $this->assertSame(['eu.dylaris.com', 'any.example.net', 'play.example.com'], self::names(DomainList::offered($entries, 'eu-central', false)));
        $this->assertSame(['eu.dylaris.com', 'vip.dylaris.com', 'any.example.net', 'play.example.com'], self::names(DomainList::offered($entries, 'eu-central', true)));
        $this->assertSame(['us.dylaris.com', 'any.example.net', 'play.example.com'], self::names(DomainList::offered($entries, 'us-east', false)));
        $this->assertSame(['any.example.net', 'play.example.com'], self::names(DomainList::offered($entries, null, false)), 'a node without a region');

        $vip = DomainList::offered($entries, 'eu-central', true)[1];
        $this->assertEquals(new AllowedDomain('vip.dylaris.com', false, true, 'dns', 'eu-central'), $vip, 'switched off reads as admins only');
    }

    public function testAMergeKeepsTheSwitchMarksUnlistedDomainsGoneAndAddsNewOnes(): void
    {
        $stored = [
            new DomainEntry('eu.dylaris.com', DomainEntry::OFFICIAL, false, 'dns', null, lastSeenAt: 1),
            new DomainEntry('old.dylaris.com', DomainEntry::OFFICIAL, true, 'dns', 'eu-central', lastSeenAt: 1),
            new DomainEntry('play.example.com', DomainEntry::OWN, true, ownState: 'pending', txtName: '_dylaris-verify.play.example.com', txtValue: 'tok'),
        ];
        $regions = new Regions(
            [new HosterDomain('eu.dylaris.com', 'letters'), new HosterDomain('new.dylaris.com', 'dns')],
            [new Region('eu-central', '*.eu.dylaris.com', true)],
        );
        $own = [new CustomDomain('play.example.com', 'verified'), new CustomDomain('elsewhere.example.com', 'verified', '_dylaris-verify.elsewhere.example.com', 't2')];

        $merged = DomainList::merge($stored, $regions, $own, self::NOW);

        $this->assertEquals([
            new DomainEntry('eu.dylaris.com', DomainEntry::OFFICIAL, false, 'letters', 'eu-central', lastSeenAt: self::NOW),
            new DomainEntry('new.dylaris.com', DomainEntry::OFFICIAL, true, 'dns', null, lastSeenAt: self::NOW),
            new DomainEntry('old.dylaris.com', DomainEntry::OFFICIAL, true, 'dns', 'eu-central', lastSeenAt: 1, gone: true),
            new DomainEntry('elsewhere.example.com', DomainEntry::OWN, false, ownState: 'verified', txtName: '_dylaris-verify.elsewhere.example.com', txtValue: 't2', lastSeenAt: self::NOW),
            new DomainEntry('play.example.com', DomainEntry::OWN, true, ownState: 'verified', txtName: '_dylaris-verify.play.example.com', txtValue: 'tok', lastSeenAt: self::NOW),
        ], $merged);

        $this->assertFalse(DomainList::merge([], $regions, [], self::NOW, false)[0]->enabled, 'after a migration that ticked nothing, new official domains start off');
        $back = DomainList::merge($merged, $regions, [], self::NOW)[2];
        $this->assertSame(['old.dylaris.com', true], [$back->domain, $back->gone]);
    }

    /** Review F1: Core lists a region only while one of its edges is registered; an outage must not make its domain global. */
    public function testAKnownRegionStaysWhenTheRegionIsMissingFromOneAnswer(): void
    {
        $hosters = [new HosterDomain('eu.dylaris.com', 'letters'), new HosterDomain('us.dylaris.com', 'dns')];
        $first = DomainList::merge([], new Regions($hosters, [new Region('eu-central', '*.eu.dylaris.com', true), new Region('us-east', '*.us.dylaris.com', true)]), [], self::NOW);

        $second = DomainList::merge($first, new Regions($hosters, [new Region('eu-central', '*.eu.dylaris.com', true)]), [], self::NOW + 1);

        $this->assertSame(['eu-central', 'us-east'], array_map(fn ($e) => $e->region, $second));
        $this->assertSame(['eu.dylaris.com'], self::names(DomainList::offered($second, 'eu-central', false)), 'the us domain stays off eu nodes');
        $this->assertNull(DomainList::merge([], new Regions([new HosterDomain('x.example.net', 'dns')], []), [], self::NOW)[0]->region, 'never matched: no region');
    }

    public function testADomainTheProviderListsAgainIsNoLongerGone(): void
    {
        $stored = [new DomainEntry('eu.dylaris.com', DomainEntry::OFFICIAL, false, 'letters', gone: true)];

        $merged = DomainList::merge($stored, new Regions([new HosterDomain('eu.dylaris.com', 'letters')], []), [], self::NOW);

        $this->assertFalse($merged[0]->gone);
        $this->assertFalse($merged[0]->enabled, 'the switch survives');
    }

    public function testTheMigrationKeepsTickedDomainsAndTurnsAdminsOnlyOff(): void
    {
        $nodeA = [
            ['domain' => 'eu.dylaris.com', 'custom' => false, 'admin_only' => false, 'validation' => 'letters'],
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => true, 'validation' => ''],
        ];
        $nodeB = [
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => false, 'validation' => ''],
            ['domain' => 'us.dylaris.com', 'custom' => false, 'admin_only' => true, 'validation' => 'dns'],
        ];

        [$entries, $newOfficialEnabled] = DomainList::fromNodeLists([$nodeA, $nodeB]);

        $this->assertEquals([
            new DomainEntry('eu.dylaris.com', DomainEntry::OFFICIAL, true, 'letters'),
            new DomainEntry('us.dylaris.com', DomainEntry::OFFICIAL, false, 'dns'),
            new DomainEntry('vip.example.com', DomainEntry::OWN, false),
        ], $entries);
        $this->assertFalse($newOfficialEnabled, 'every node saved a list: official domains nobody ticked start off');
    }

    /** Review F2 (coordinator ruling): an explicit admins-only mark always wins; an unsaved gateway node only turns new official domains on. */
    public function testANodeWithoutASavedListTurnsNewOfficialDomainsOnButNeverAnAdminsOnlyMark(): void
    {
        $saved = [
            ['domain' => 'us.dylaris.com', 'custom' => false, 'admin_only' => true, 'validation' => 'dns'],
            ['domain' => 'eu.dylaris.com', 'custom' => false, 'admin_only' => false, 'validation' => 'letters'],
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => true],
        ];

        [$entries, $newOfficialEnabled] = DomainList::fromNodeLists([$saved, null]);

        $this->assertTrue($newOfficialEnabled);
        $this->assertSame(['eu.dylaris.com' => true, 'us.dylaris.com' => false, 'vip.example.com' => false], array_combine(self::names($entries), array_map(fn ($e) => $e->enabled, $entries)));
        $this->assertSame([[], true], DomainList::fromNodeLists([]), 'no gateway node at all');
    }

    public function testASyncStoresTheListAndAFailureKeepsIt(): void
    {
        $this->provider->customDomains['play.example.com'] = ['domain' => 'play.example.com', 'state' => 'verified'];

        $this->list->sync(self::NOW);

        $this->assertSame(['eu.dylaris.com', 'us.dylaris.com', 'play.example.com'], self::names($this->store->domains()));
        $this->assertSame(['eu-central', 'us-east', null], array_map(fn ($e) => $e->region, $this->store->domains()));
        $this->assertEquals(new DomainSync(self::NOW, self::NOW, null, true), $this->store->domainSync());
        $this->assertSame('Domains: 2 official, 1 own, 0 no longer offered; 1 admins only.', DomainList::summary($this->store->domains()), 'an own domain found at the provider starts off');

        $this->provider->failOn['GET regions'] = new Response(503, [], '{"error":"The provider is down."}');
        try {
            $this->list->sync(self::NOW + 10);
            $this->fail('expected ProviderException');
        } catch (ProviderException) {
        }
        $this->assertCount(3, $this->store->domains(), 'the stored list stays');
        $this->assertEquals(new DomainSync(self::NOW, self::NOW + 10, 'The provider is down.', true), $this->store->domainSync());
    }

    /** Owner review 0.2.0: the sync reads, then writes; a switch flipped in between must survive the write. */
    public function testASwitchFlippedWhileASyncRunsSurvivesIt(): void
    {
        $this->list->sync(self::NOW);
        $racing = new RacingStateStore($this->store, fn () => $this->store->setDomainEnabled('eu.dylaris.com', false));
        $list = new DomainList($this->provider->client(), $racing);

        $list->sync(self::NOW + 10);

        $this->assertTrue($racing->fired, 'the toggle ran between the read and the write');
        $this->assertFalse($this->store->domains()[0]->enabled, 'the sync did not write back its older read');
        $this->assertSame(self::NOW + 10, $this->store->domains()[0]->lastSeenAt, 'the sync still updated the row');
    }

    public function testEnsureFreshSyncsAnEmptyOrStaleListAndBacksOffAfterAFailure(): void
    {
        $this->list->ensureFresh(self::NOW);
        $this->assertSame(['GET regions', 'GET custom-domains'], $this->provider->calls, 'empty: synced');

        $this->list->ensureFresh(self::NOW + DomainList::MAX_AGE);
        $this->assertCount(2, $this->provider->calls, 'an hour old is still fresh');

        $this->provider->failOn['GET regions'] = new Response(503, [], '{"error":"down"}');
        $this->list->ensureFresh(self::NOW + DomainList::MAX_AGE + 1);
        $this->assertCount(3, $this->provider->calls, 'stale: tried, the failure swallowed');
        $this->assertSame('down', $this->store->domainSync()->error);
        $this->assertCount(2, $this->store->domains());

        $this->list->ensureFresh(self::NOW + DomainList::MAX_AGE + 2);
        $this->assertCount(3, $this->provider->calls, 'no new attempt within RETRY_AFTER');
        $this->list->ensureFresh(self::NOW + DomainList::MAX_AGE + 2 + DomainList::RETRY_AFTER);
        $this->assertCount(5, $this->provider->calls);
        $this->assertNull($this->store->domainSync()->error);
    }

    public function testAnOwnDomainIsAddedPendingWithItsRecordThenVerified(): void
    {
        $this->list->sync(self::NOW);

        $added = $this->list->addOwn(' Play.Example.com. ');

        $this->assertSame(['play.example.com', 'pending', '_dylaris-verify.play.example.com', 'token-play.example.com', true], [$added->domain, $added->ownState, $added->txtName, $added->txtValue, $added->enabled]);
        $this->assertSame(['play.example.com'], array_column(array_slice($this->provider->bodies, -1), 'domain'));
        $this->assertNotContains('play.example.com', self::names(DomainList::offered($this->store->domains(), 'eu-central', true)), 'pending: nobody can pick it');

        $this->assertSame('play.example.com is verified. Users can pick it once it is enabled.', $this->list->verifyOwn('play.example.com'));
        $this->assertContains('POST custom-domains/play.example.com/verify-txt', $this->provider->calls);
        $this->assertContains('play.example.com', self::names(DomainList::offered($this->store->domains(), 'eu-central', false)));

        $this->list->sync(self::NOW + 1);
        $this->assertSame('verified', $this->store->domains()[2]->ownState, 'the provider says the same on the next sync');
    }

    public function testAFailedVerifyKeepsTheDomainPending(): void
    {
        $this->list->addOwn('play.example.com');
        $this->provider->failOn['POST custom-domains/play.example.com/verify-txt'] = new Response(409, [], '{"error":"The TXT record was not found yet. DNS changes can take a few minutes to publish."}');

        try {
            $this->list->verifyOwn('play.example.com');
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('pending', $this->store->domains()[0]->ownState);
    }

    public function testAVerifyWithoutARecordAsksForOneFirst(): void
    {
        $this->store->putDomains([new DomainEntry('play.example.com', DomainEntry::OWN, false, ownState: 'pending')]);
        $this->provider->customDomains['play.example.com'] = ['domain' => 'play.example.com', 'state' => 'pending'];

        $this->assertSame(
            'Publish the TXT record _dylaris-verify.play.example.com with the value token-play.example.com first, then verify.',
            $this->refusal(fn () => $this->list->verifyOwn('play.example.com')),
        );
        $this->assertSame(['POST custom-domains/play.example.com/txt-token'], $this->provider->calls);
        $this->assertSame('token-play.example.com', $this->store->domains()[0]->txtValue);
    }

    public function testBadOrDuplicateOwnDomainsAreRefusedBeforeTheProvider(): void
    {
        $this->list->sync(self::NOW);
        $this->provider->calls = [];

        $this->assertSame('Enter a domain name like play.example.com.', $this->refusal(fn () => $this->list->addOwn('not a domain')));
        $this->assertSame('Enter a domain name like play.example.com.', $this->refusal(fn () => $this->list->addOwn('localhost')));
        $this->assertSame('eu.dylaris.com is already in the list.', $this->refusal(fn () => $this->list->addOwn('eu.dylaris.com')));
        $this->assertSame([], $this->provider->calls);
    }

    public function testRemovingReleasesAnOwnDomainAndRefusesAListedOfficialOne(): void
    {
        $this->list->sync(self::NOW);
        $this->list->addOwn('play.example.com');

        $this->assertSame(
            'An official domain stays in the list while the provider offers it. Switch it off instead.',
            $this->refusal(fn () => $this->list->remove('eu.dylaris.com')),
        );
        $this->list->remove('play.example.com');
        $this->assertContains('DELETE custom-domains/play.example.com', $this->provider->calls);
        $this->assertSame(['eu.dylaris.com', 'us.dylaris.com'], self::names($this->store->domains()));

        // A domain the provider dropped goes from the list without a provider call.
        $this->provider->regions['hosterDomains'] = [['domain' => 'eu.dylaris.com', 'validation' => 'letters']];
        $this->list->sync(self::NOW + 1);
        $calls = count($this->provider->calls);
        $this->list->remove('us.dylaris.com');
        $this->assertCount($calls, $this->provider->calls);
        $this->assertSame(['eu.dylaris.com'], self::names($this->store->domains()));
    }

    /** Review F4: verified in the DYLARIS panel meanwhile, the provider refuses a second verify; the row follows its list. */
    public function testVerifyingADomainAlreadyVerifiedAtTheProviderUpdatesTheRow(): void
    {
        $this->list->addOwn('play.example.com');
        $this->provider->customDomains['play.example.com']['state'] = 'verified';
        $this->provider->failOn['POST custom-domains/play.example.com/verify-txt'] = new Response(400, [], '{"error":"That domain is already verified"}');

        $this->assertSame('play.example.com is verified. Users can pick it once it is enabled.', $this->list->verifyOwn('play.example.com'));
        $this->assertSame('verified', $this->store->domains()[0]->ownState);
    }

    /** Review F3: a sync and the admin's add, verify and remove each run alone; a held lock makes them wait or refuse. */
    public function testListChangesRunUnderTheLockAndTheOnDemandSyncSkipsAHeldOne(): void
    {
        $held = false;
        $runs = [];
        $lock = function (callable $f, bool $wait) use (&$held, &$runs) {
            $runs[] = $wait;
            if ($held) {
                if ($wait) {
                    throw new ModeRefused(DomainList::BUSY);
                }
                return null;
            }
            return $f();
        };
        $list = new DomainList($this->provider->client(), $this->store, $lock(...));

        $list->sync(self::NOW);
        $list->addOwn('play.example.com');
        $list->verifyOwn('play.example.com');
        $list->remove('play.example.com');
        $this->assertSame([true, true, true, true], $runs, 'sync, add, verify and remove each waited for the lock');

        $held = true;
        $calls = count($this->provider->calls);
        $this->assertSame(DomainList::BUSY, $this->refusal(fn () => $list->addOwn('other.example.com')));
        $list->ensureFresh(self::NOW + DomainList::MAX_AGE + 1);
        $this->assertSame(false, end($runs), 'the on-demand sync does not wait');
        $this->assertCount($calls, $this->provider->calls, 'and asks the provider nothing while another sync runs');
    }

    /** Review F5: the provider's Retry-After and the reconcile's wait both hold the on-demand sync back. */
    public function testTheOnDemandSyncHonoursRetryAfter(): void
    {
        $this->provider->failOn['GET regions'] = new Response(429, ['Retry-After' => '3600'], '{"error":"Too many requests."}');
        $this->list->ensureFresh(self::NOW);
        $this->assertCount(1, $this->provider->calls);

        $this->list->ensureFresh(self::NOW + DomainList::RETRY_AFTER + 1);
        $this->assertCount(1, $this->provider->calls, '5 minutes are not enough when the provider said an hour');
        $this->list->ensureFresh(self::NOW + 3601);
        $this->assertCount(3, $this->provider->calls);

        $store = new InMemoryStateStore();
        $store->put(new \Dylaris\GatewayPanel\ServerState(7, \Dylaris\GatewayPanel\Mode::Both, 'a', 'eu.dylaris.com', 1, 25565, retryAfterUntil: self::NOW + 60));
        $provider = new FakeProvider();
        (new DomainList($provider->client(), $store))->ensureFresh(self::NOW);
        $this->assertSame([], $provider->calls, 'the reconcile was told to wait: so is the page');
    }

    public function testAnOwnDomainAlreadyGoneAtTheProviderIsRemovedAllTheSame(): void
    {
        $this->list->addOwn('play.example.com');
        $this->provider->failOn['DELETE custom-domains/play.example.com'] = new Response(404, [], '{"error":"No claim on that domain for your account"}');

        $this->list->remove('play.example.com');

        $this->assertSame([], $this->store->domains());
    }

    public function testTheStatusSaysWhatTheAdminNeedsToKnow(): void
    {
        $this->assertSame(
            ['Official', 'No longer offered', 'Own domain, verified', 'Own domain, not verified yet', 'Own domain, blocked', 'No longer at DYLARIS'],
            array_map(fn (DomainEntry $e) => $e->status(), [
                new DomainEntry('a.example.net', DomainEntry::OFFICIAL, true),
                new DomainEntry('b.example.net', DomainEntry::OFFICIAL, true, gone: true),
                new DomainEntry('c.example.com', DomainEntry::OWN, true, ownState: 'verified'),
                new DomainEntry('d.example.com', DomainEntry::OWN, true, ownState: 'pending'),
                new DomainEntry('e.example.com', DomainEntry::OWN, true, ownState: 'blocked'),
                new DomainEntry('f.example.com', DomainEntry::OWN, true, ownState: 'verified', gone: true),
            ]),
        );
    }

    public function testTheSwitchChangesOnlyThatDomain(): void
    {
        $this->list->sync(self::NOW);

        $this->list->setEnabled('us.dylaris.com', false);

        $this->assertSame([true, false], array_map(fn ($e) => $e->enabled, $this->store->domains()));
        $this->assertSame('That domain is not in the list.', $this->refusal(fn () => $this->list->setEnabled('nope.example.com', true)));
    }

    private function refusal(callable $f): string
    {
        try {
            $f();
        } catch (ModeRefused $e) {
            return $e->userMessage;
        }
        $this->fail('expected ModeRefused');
    }
}
