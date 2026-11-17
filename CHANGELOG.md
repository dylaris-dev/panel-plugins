# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).
One version covers both plugins; the release assets are `dylaris-gateway-pelican-<version>.zip` (Pelican) and
`dylaris-gateway-pterodactyl-<version>.pteroext` (Pterodactyl 2.0).

## [Unreleased]

## [0.1.0] - 2026-11-17

First public release, for both plugins.

### Added
- Pelican plugin (Pelican `1.0.0-rc.3`) and Pterodactyl 2.0 extension (Pterodactyl `2.0.0-beta.2`): per server Direct,
  Both or Gateway only, an address on the provider's hoster domain, Real player IP (Gateway only) and Bedrock with the
  Floodgate key download.
- Node tab: connect a node to a region, the link compose snippet shown once, allowed modes per node, disconnect.
- Settings: provider URL and an API key stored encrypted with the panel's `APP_KEY`, with a connection test.
- Reconcile every 5 minutes (`dylaris-gateway:reconcile`): deleted servers, changed ports, moved servers and missing
  routes are repaired; the run honours `Retry-After`.
- Subuser permission to manage the gateway (`gateway.manage` on Pelican, `ext.dylaris-gateway.manage` on Pterodactyl).
- Mode changes undo their own steps when a call fails, including a route the provider may have created on a call that
  got no answer or a 5xx.

### Security
- A saved API key is only ever sent to the saved provider URL: a new URL is saved or tested only with the key typed
  again.
- Pelican: the link key does not stay in the node tab's Livewire state after Connect.

### Known limitations
- Ports 1024 and below cannot use the gateway modes.
- A route created by hand in the DYLARIS dashboard on the link of a plugin node, under an address name a server of that
  panel uses or keeps, is deleted or retargeted by the reconcile. Routes under other names are never touched.
- A Bedrock join has not been proven with a real Bedrock client yet.
- Pterodactyl: the loopback allocation is hidden in the UI only; the reconcile repairs a change within 5 minutes.
- Pterodactyl 1.x has no plugin system; see `docs/pterodactyl-1x.md` (not tested on a 1.x panel).

[Unreleased]: https://github.com/dylaris-dev/panel-plugins/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dylaris-dev/panel-plugins/releases/tag/v0.1.0
