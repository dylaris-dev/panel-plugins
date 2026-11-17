# DYLARIS Gateway panel plugins

Free plugins that put the [DYLARIS](https://dylaris.com) Managed Gateway in front of your Wings nodes, managed from
inside the game panel you already run:

| Plugin | Panel | Folder | Guide |
|---|---|---|---|
| DYLARIS Gateway for Pelican | Pelican `1.0.0-rc.3` | `pelican/dylaris-gateway` | [docs/install-pelican.md](docs/install-pelican.md) |
| DYLARIS Gateway for Pterodactyl 2.0 | Pterodactyl `2.0.0-beta.2` | `pterodactyl/dylaris-gateway` | [docs/install-pterodactyl.md](docs/install-pterodactyl.md) |

They are for hosters on Pelican or Pterodactyl 2.0 who want their game servers reachable through the gateway instead of
(or as well as) the node's public IP. Want to connect a different panel or your own hosting system? See
[docs/integration-guide.md](docs/integration-guide.md).

## What you get

- **Players connect to the edge, not to your node.** The gateway address resolves to the DYLARIS edge, and your node
  only talks to it over an outbound tunnel it opens itself. In Gateway only mode the node's public port is gone as
  well. Plugins, map services and old DNS records can still reveal the node's address; a tunnel cannot hide those.
- **Junk stops at the edge.** The edge reads the Minecraft handshake of every connection, drops malformed and stalled
  ones and rate-limits new connections per IP. Large volumetric attacks are filtered by the network-level DDoS
  protection of the data centres the edges run in.
- **Real player IP.** In Gateway only mode the route can send a PROXY protocol header, so Paper or Velocity sees the
  player's address instead of the gateway's.
- **Bedrock players** through Floodgate, with the key download in the panel.
- **Per server, three modes**, switched by the server's owner, an admin or a subuser with the gateway permission:

| Mode | In plain words |
|---|---|
| Direct | Nothing changes: players use the node's IP and port. No gateway address. |
| Both | The gateway address works, and the old IP and port keep working too. A safe way to move players over. |
| Gateway only | Only the gateway address works. The public port is released, so the node IP is no longer an entry point. |

## Requirements

- **Pelican** `1.0.0-rc.3` or a later `1.x` that the plugin's `panel_version` `^1.0.0-rc.3` accepts, with PHP 8.4. Tested
  with Wings `1.0.0-rc.2`.
- **Pterodactyl** `2.0.0-beta.2` or later in `^2.0.0-beta.2`, SDK `^2.0.0-beta.6`, PHP 8.4. Tested with Wings `1.13.4`.
- A **DYLARIS account with a Managed Gateway plan**, and an **API key with `gateway.read` and `gateway.write`**, used by
  this one panel install only.
- **Docker on each Wings node**, for the link container.
- The panel's scheduler (cron) running, for the reconcile every 5 minutes.

## Quick start

1. Download from [GitHub Releases](https://github.com/dylaris-dev/panel-plugins/releases):
   `dylaris-gateway-pelican-<version>.zip` for Pelican or `dylaris-gateway-pterodactyl-<version>.pteroext` for Pterodactyl 2.0, plus
   `SHA256SUMS`. Check it with `grep <file> SHA256SUMS | sha256sum -c` (works with the busybox `sha256sum` in the panel images too).
2. Install it.
   - Pelican: unzip into `plugins/`, then `php artisan p:plugin:install dylaris-gateway`.
     Full guide: [docs/install-pelican.md](docs/install-pelican.md).
   - Pterodactyl 2.0: `php artisan p:extension:install dylaris-gateway-pterodactyl-<version>.pteroext --enable`.
     Full guide: [docs/install-pterodactyl.md](docs/install-pterodactyl.md).
3. In the admin area, enter the provider URL (`https://panel.dylaris.com`) and the API key, and run the connection test.
4. On a node's **Gateway** tab, pick a region and connect. Copy the Docker Compose snippet shown once to the node and run
   `docker compose up -d`.
5. On a server, pick a mode and an address name, apply, and restart the server.

**Pterodactyl 1.x** has no plugin system. The gateway still works with it, managed from the DYLARIS dashboard:
[docs/pterodactyl-1x.md](docs/pterodactyl-1x.md). That guide was not tested on a 1.x panel.

## How it works

```
                    DYLARIS                                  your Wings node
 player  --->  edge (public, filters junk)    <=== tunnel ===  link container  --->  game server
 (survival.your-hoster-domain)                (opened by the link)  (host network)    (127.0.0.1:<port> allocation,
                                                                                       bound by Wings on its Docker
                                                                                       interface, 172.18.0.1)
```

- Connecting a node mints a **link kit** at the provider. The link container on the node uses its key to open an
  outbound tunnel to the edge; nothing on the node has to accept inbound gateway traffic.
- Switching a server to Both or Gateway only adds a `127.0.0.1:<port>` allocation to it. Wings binds such an allocation to
  its Docker interface (`172.18.0.1` by default), which is where the link reaches the server. The plugin then creates a
  **route** at the provider: address name plus hoster domain, pointing at that interface and port through the node's link.
- Gateway only makes the loopback allocation the primary and releases the public one. Going back takes the old public
  address if it is still free.
- A **reconcile** every 5 minutes repairs what changed in the panel: deleted servers, changed ports, servers moved to
  another node, routes missing at the provider.

The plugin never restarts a server and never edits server files; it says when a restart or a config change is needed.

## Security notes

- The API key is stored **encrypted with the panel's `APP_KEY`** and never shown again after saving. A saved key is only
  ever sent to the saved provider URL; changing the URL needs the key typed again.
- **What is sent where:** the panel calls only the provider URL you configure (default `https://panel.dylaris.com`),
  under `/api/external/gateway/*`, with the API key as a Bearer token. It sends what a route needs: node names (as link
  kit names), the region, the address name, the target interface IP and port, and the Real IP and Bedrock switches. It
  reads back account, regions, link kits, routes and player counts for the status lines.
- The link container on the node pulls `ghcr.io/dylaris-dev/gateway-link` and talks to the provider and the edge. The
  link key is in the compose file only: the panel does not store it, so keep that file private (mode 600).
- **No telemetry.** The plugins make no other outbound calls.
- Report a security issue privately through the repository's GitHub security advisories, not in a public issue.

## Known limitations

- Ports 1024 and below cannot use the gateway modes (the panels allow no `127.0.0.1` allocation there).
- A route you create by hand in the DYLARIS dashboard on the link of a plugin node, under an address name a server of
  that panel uses or keeps, is deleted or retargeted by the reconcile. Use a name no panel server uses, or leave plugin
  nodes' links to the plugin.
- A Bedrock join has not been proven with a real Bedrock client yet. The switch, the key download and Floodgate loading
  the key were tested.
- Pterodactyl: the loopback allocation is hidden in the panel UI only; a subuser with allocation rights could still
  change it through the panel API. The reconcile repairs that within 5 minutes.
- Region choice for a node is final unless you disconnect it (which retires its link kit, and kits are limited).
- Provider rate limits apply per key (see the install guides); the plugins honour `Retry-After`.

## Development

Layout:

- `core/` - framework-free PHP library (`Dylaris\GatewayPanel\`) shared by both plugins: provider API client, mode
  changes, reconcile, status. `tools/build-plugins.sh` copies `core/src` into each plugin as `core-src/` (git-ignored).
- `pelican/dylaris-gateway/` - Filament plugin for Pelican.
- `pterodactyl/dylaris-gateway/` - Pterodactyl 2.0 extension (PHP backend, React UI in `ui/`).
- `tools/` - tests and packaging, all in Docker (through WSL on Windows).

Tests (need Docker):

```
bash tools/test.sh            # core: composer + PHPUnit on PHP 8.3
bash tools/test-pelican.sh    # Pelican plugin, inside a Pelican v1.0.0-rc.3 checkout (docker volume dylaris-pelican-rc3)
bash tools/test-ptero.sh      # Pterodactyl extension, inside a v2.0.0-beta.2 checkout against MariaDB 11
```

The panel checkouts are cloned into docker volumes on the first run and reused after that. Extra arguments go to
PHPUnit, for example `bash tools/test-pelican.sh --filter SettingsTest`.

Build the release files:

```
bash tools/build-release.sh   # both packages plus dist/SHA256SUMS
```

That runs `tools/build-plugins.sh` (copies the core), `tools/pack-pelican.py` (`dist/dylaris-gateway-pelican-<version>.zip`)
and `tools/pack-ptero.sh` (`dist/dylaris-gateway-pterodactyl-<version>.pteroext`, packed with the panel's own `p:extension:pack`
after `tools/build-ptero-ui.sh` built the UI against the panel's SDK). The version comes from
`pelican/dylaris-gateway/plugin.json` and `pterodactyl/dylaris-gateway/extension.json`.

CI (`.github/workflows/ci.yml`) runs the three test suites and the package build on every push to `main` and every pull
request. Pushing a tag `v<version>` runs `.github/workflows/release.yml`: it refuses a tag that does not match both
manifests, builds both packages and publishes them with `SHA256SUMS` as a GitHub Release.

## Contributing

Issues and pull requests are welcome. Please:

- keep changes to the shared logic in `core/src`, with a PHPUnit test in `core/tests`; panel-specific code goes into
  the plugin it concerns, with a test in its `tests/`,
- keep both plugins in step: a behaviour change on one panel usually belongs on the other too,
- run the three test suites before you open the pull request, and add a line to `CHANGELOG.md` under `Unreleased` for
  anything a hoster would notice.

## License

MIT, see [LICENSE](LICENSE).
