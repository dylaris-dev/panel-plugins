DYLARIS Managed Gateway for your game panel. Pick the file for the panel you run:

| File | Panel | Install |
|---|---|---|
| `dylaris-gateway-pelican-{{VERSION}}.zip` | Pelican 1.0 | unzip into the panel's `plugins/` folder, then `php artisan p:plugin:install dylaris-gateway` |
| `dylaris-gateway-pterodactyl-{{VERSION}}.pteroext` | Pterodactyl 2.0 | `php artisan p:extension:install dylaris-gateway-pterodactyl-{{VERSION}}.pteroext --enable` |
| `SHA256SUMS` | both | check your download: `sha256sum --ignore-missing -c SHA256SUMS` |

Pterodactyl 1.x has no plugin system; see [docs/pterodactyl-1x.md](https://github.com/dylaris-dev/panel-plugins/blob/main/docs/pterodactyl-1x.md).

You need a DYLARIS account with a Managed Gateway plan and an API key with `gateway.read` and `gateway.write`.
Full guides: [Pelican](https://github.com/dylaris-dev/panel-plugins/blob/main/docs/install-pelican.md),
[Pterodactyl](https://github.com/dylaris-dev/panel-plugins/blob/main/docs/install-pterodactyl.md).
What changed: [CHANGELOG.md](https://github.com/dylaris-dev/panel-plugins/blob/main/CHANGELOG.md).
