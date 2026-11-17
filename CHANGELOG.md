# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).
One version covers both plugins; the release assets are `dylaris-gateway-pelican-<version>.zip` (Pelican) and
`dylaris-gateway-pterodactyl-<version>.pteroext` (Pterodactyl 2.0).

## [Unreleased]

## [0.2.0] - 2026-11-17

### Added
- **Gateway domains**, one list of address domains for the whole panel (Pelican: admin area, Advanced; Pterodactyl
  2.0: an admin screen of its own). DYLARIS' official domains arrive through the API on their own: hourly
  (`dylaris-gateway:sync-domains`), when the list is empty or older than an hour at the moment it is needed, and with
  **Refresh**. A failed sync keeps the last list and shows when it last succeeded.
- A switch per domain: on, users may pick it; off, admins only.
- Own domains are added and verified in the plugin: enter the domain, publish the TXT record shown, click Verify. Only a
  verified own domain can be picked. Remove releases the claim at DYLARIS.
- The region of each official domain is derived from the provider's region wildcards and shown in the list and in the
  address dropdown. A node offers the domains of its own region and those without one.

### Changed
- The per-node domain list on the node's Gateway tab is gone. Updating moves it into the panel-wide list: a domain
  ticked on any node is kept, one marked Admins only on any node is switched off; if any node never saved a list,
  every official domain is switched on, otherwise the official domains no node ticked start switched off.
- New official domains arrive switched on, so users get DYLARIS' domains without an admin step.
- An official domain DYLARIS no longer lists stays in the list as `No longer offered` and cannot be picked; existing
  addresses under it are left alone.
- The address dropdown reads the list from the panel's database on every view instead of a 10 minute cache, so a
  change to the list shows at once.
- Docs: the Bedrock steps say that a server on an older Minecraft Java version also needs ViaVersion, because DYLARIS'
  Geyser always speaks the newest Java version; without it Bedrock players see `Outdated server!`.

## [0.1.0] - 2026-11-17

First public release, for both plugins.

### Added
- Pelican plugin (Pelican `1.0.0-rc.3`) and Pterodactyl 2.0 extension (Pterodactyl `2.0.0-beta.2`): per server Direct,
  Both or Gateway only, Real player IP (Gateway only) and Bedrock with the Floodgate key download.
- A **Gateway** page in each server's navigation, on both panels.
- Node tab: connect a node to a region, the link compose snippet shown once, allowed modes per node, the address
  domains users may pick (the provider's hoster domains and the account's verified own domains, each optionally for
  admins only), disconnect.
- Addresses: users pick a domain from the node's list and type only the name in front of it; an address can be changed
  later (the new address works first, then the old one is removed). A name that already has a route at the provider is
  never taken.
- Three permissions per server: view (address and status), mode (Direct, Both, Gateway only, Real player IP, Bedrock)
  and address (choose or change the address). Pelican admin roles get the same three.
- A **Restart now** button after a switch that needs a restart, for whoever may restart the server.
- Eggs that may use the gateway, in the settings. By default the Java Minecraft eggs; any other server reads `The gateway
  supports Minecraft servers only.`
- Settings: an API key stored encrypted with the panel's `APP_KEY`, with a connection test. The provider is fixed to
  `https://panel.dylaris.com`.
- Reconcile every 5 minutes (`dylaris-gateway:reconcile`): deleted servers, changed ports, moved servers and missing
  routes are repaired; the run honours `Retry-After`.
- Mode and address changes undo their own steps when a call fails, including a route the provider may have created on a
  call that got no answer or a 5xx.

### Security
- The API key is only ever sent to the provider; the provider URL cannot be changed from the panel.
- Pelican: the link key does not stay in the node tab's Livewire state after Connect.

### Known limitations
- Ports 1024 and below cannot use the gateway modes.
- A route created by hand in the DYLARIS dashboard on the link of a plugin node, under an address name a server of that
  panel uses or keeps, is deleted or retargeted by the reconcile. Routes under other names are never touched.
- An own domain is always the whole address; a name in front of it would be a different, unverified name.
- A Bedrock join has not been proven with a real Bedrock client yet.
- Pterodactyl: the loopback allocation is hidden in the UI only; the reconcile repairs a change within 5 minutes.
- Pterodactyl 1.x has no plugin system; see `docs/pterodactyl-1x.md` (not tested on a 1.x panel).

[Unreleased]: https://github.com/dylaris-dev/panel-plugins/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/dylaris-dev/panel-plugins/releases/tag/v0.2.0
[0.1.0]: https://github.com/dylaris-dev/panel-plugins/releases/tag/v0.1.0
