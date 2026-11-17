# DYLARIS Gateway for Pelican

Puts a game server behind the DYLARIS Managed Gateway from inside the Pelican panel. Each server runs in one of three
modes:

| Mode | What it does |
|---|---|
| Direct | Players connect to the node's public IP and port, as before. No gateway address. |
| Both | Players can use the gateway address or the direct address. |
| Gateway only | Only the gateway address works. The public port is closed: the server listens on the node's Docker interface only. |

Tested against Pelican `1.0.0-rc.3` with Wings `1.0.0-rc.2`. The plugin declares `panel_version` `^1.0.0-rc.3` and
needs PHP 8.4, which Pelican rc.3 requires anyway.

## What you need

- A DYLARIS account on the managed gateway plan, and an API key with `gateway.read` and `gateway.write`.
- **A dedicated provider API key for this panel install.** Never share one key between two panels, and never reuse the key
  of a DYLARIS Core you run yourself: such a Core releases the own-domain claims it does not know about every 5 minutes,
  so two installs sharing one provider account work against each other.
- Docker on each Wings node: the link container runs there (the plugin shows the compose file).

## Download

Download `dylaris-gateway-pelican-<version>.zip` and `SHA256SUMS` from the latest release on
[GitHub Releases](https://github.com/dylaris-dev/panel-plugins/releases), into the panel directory, and check the file
before you install it:

```
grep dylaris-gateway-pelican-<version>.zip SHA256SUMS | sha256sum -c
```

It must print `dylaris-gateway-pelican-<version>.zip: OK`. Without `sha256sum`, compare the output of
`shasum -a 256 <file>` (macOS) or `Get-FileHash <file>` (Windows PowerShell) with the file's line in `SHA256SUMS`.

## Install

1. Unzip `dylaris-gateway-pelican-<version>.zip` into the panel's `plugins/` folder, so that `plugins/dylaris-gateway/plugin.json`
   exists.
2. Run `php artisan p:plugin:install dylaris-gateway` from the panel directory. It prints `Plugin installed and enabled.`
3. Pelican keeps a plugin's status in `plugin.json`. Overwriting the folder with a fresh copy resets the status to not
   installed, so run `p:plugin:install` again after every update.

## Connect the panel to the provider

1. Admin area, Plugins, DYLARIS Gateway, Settings.
2. Enter the API key. There is no provider URL to enter: the plugin always calls `https://panel.dylaris.com`. (The
   environment variable `DYLARIS_GATEWAY_PROVIDER_URL` overrides it; it exists for DYLARIS' own tests. It is read through the panel's config, so it survives
   `php artisan config:cache` (clear and rebuild the cache after changing it), and it must be an `https://` URL: anything
   else stops every gateway call with a message saying so, it never falls back to the production provider.)
3. Test connection (a link on the API key field). Success reads `Connected: managed gateway plan with read and write
   access.` Otherwise the message says what is missing.
4. **Eggs that may use the gateway.** The gateway carries Minecraft Java only. Until you save this list, the eggs with
   the `minecraft` tag or the `eula` or `java_version` feature are allowed, without Bedrock Dedicated Server eggs (they
   speak a protocol the edge does not route). Once saved, exactly the ticked eggs are allowed; tick an egg you import
   later here.
5. Save. The key is stored encrypted with the panel's `APP_KEY` and is never shown again: the field stays empty and the
   help text says a key is saved. Leave it blank to keep it.

If `APP_KEY` changes, the stored key can no longer be decrypted. The plugin then says `The stored API key can no longer be
read. Enter it again.` Enter the key again in Settings.

## Connect a node

Admin area, Nodes, pick the node, Gateway tab.

1. Pick the **region** the node belongs to. It cannot be changed later without retiring this node's link kit, and the
   number of kits is limited, so choose with care. The confirm dialog says the same.
2. **Wings interface IP.** The default is `172.18.0.1`. Wings does not bind a `127.0.0.1` allocation to loopback: it
   rewrites it to its Docker network interface (`docker.network.interface` in the Wings config), and the link reaches the
   server there. If the node's Docker network differs from the default, change this value to that interface's IP before
   you connect.
3. Connect node, confirm. The plugin mints a link kit and shows a Docker Compose snippet **once**. Copy it to the node
   (for example `/opt/dylaris-link/docker-compose.yml`, mode 600) and start it with `docker compose up -d`. The panel does
   not store the link key and the snippet is gone after a reload; to get a new one, disconnect the node and connect it
   again.
4. The tab shows `Link offline` until the link is up, then `Link online`.
5. **Allowed modes** limits what servers on this node may use (Direct, Both, Gateway only). Panel admins can always pick
   every mode.

6. The address domains are no longer set per node: see [Address domains](#address-domains).

Disconnect node revokes the node's link kit.

## Address domains

Admin area, Advanced, **Gateway domains**. One list for the whole panel: the domains users pick from for a server's gateway address.

- **Official domains** are DYLARIS' hoster domains (for example `eu.dylaris.com`). They arrive on their own: the list
  syncs every hour (`dylaris-gateway:sync-domains`), when it is empty or older than an hour at the moment it is needed,
  and when you click **Refresh**. A new official domain arrives switched on. An official domain DYLARIS no longer lists
  stays in the list as `No longer offered`, cannot be picked, and can be removed; addresses under it are left alone.
  A failed sync keeps the last list and says when it last succeeded.
- **Region.** Each official domain belongs to the region whose wildcard is `*.` plus the domain (`*.eu.dylaris.com` is
  region `eu-central`). A node offers the domains of its own region and those without a region (own domains, and an
  official domain no region matches). The region is shown next to each domain, here and in the address dropdown.
- **The switch.** On: users may pick the domain. Off: panel admins only. Switching never changes an existing address; it
  only decides what can be picked for a new address or a rename. A Direct server whose remembered address is on a
  domain no longer offered to the user switching it cannot switch back with it: `The address <name> is no longer
  offered on this node. Someone with the address permission has to choose a new one first.`
- **Own domains.** Add own domain, enter the name (`play.example.com`). The plugin claims it at DYLARIS and shows the TXT
  record (`_dylaris-verify.<name>` and its value). Publish it, then click **Verify**. Only a verified own domain can be
  picked. Players reach it once it also has a `CNAME` to the gateway region, as for any own domain. An own domain is
  the **whole address**: the provider verifies each exact name, so a name in front of it would be a different,
  unverified one; to offer several addresses, add each name. Remove releases the claim at DYLARIS, which DYLARIS refuses
  while a route still uses it. Your plan's own-domain limit applies. Own domains added in the DYLARIS panel directly
  show up with the next sync, switched off.
- A hoster domain takes a name in front of it: `survival.eu.dylaris.com`.

Updating from 0.1.x moves the per-node lists into this list: a domain ticked on any node is kept, and one marked Admins
only on any node is switched off, always. Only gateway nodes count: if a connected node never saved a list (it offered
every official domain), official domains new to the list arrive switched on; otherwise the official domains no node
ticked start switched off. Panel nodes never set up for the gateway are ignored. Regions and own-domain states arrive
with the first sync after the update: click **Refresh** once after updating.

## Permissions

Three subuser permissions per server, under **DYLARIS gateway** in the server's subuser settings:

| Permission | Allows |
|---|---|
| `gateway.view` | Seeing the Gateway page: address and status. |
| `gateway.mode` | Switching Direct, Both and Gateway only (this creates or removes the route), Real player IP, Bedrock and the Floodgate key download. |
| `gateway.address` | Choosing the address and changing it later. |

Mode and address include view. The server's owner and admins with **update server** hold all three. Admin roles also
get them, as **Gateway: view, mode, address** in the role editor: a role that holds one has it on every server the role
may view.

## Switch a server

The server's **Gateway** page sits in the server's navigation, next to Network.

1. Pick a mode. Whoever has the address permission also picks a **domain** from the list the node offers and types the **address
   name** in front of it (not for an own domain, which is the whole address). The page offers the server's name reduced
   to what the hoster domain allows (letters only, letters and digits, or a DNS label; at most 63 characters). If
   nothing usable is left or the name is taken, it offers a random one. Without the address permission the plugin picks
   the name on the first domain. A name that already has a route at the provider is never taken, even if the
   provider's name check calls it free (that check answers "free" for every name the same account holds): taking it
   would move that route, and its players, to this server. A refusal names the rule that applied.
2. Apply mode. Some switches ask for confirmation first and spell out what changes (a different direct port, Real IP
   being switched off).
3. **Restart the server.** Allocation changes only take effect on a restart. The plugin never restarts a server by
   itself; the page shows a banner until it sees the server running again, with a **Restart now** button for anyone who
   may restart the server (`control.restart`).

### Change the address

Pick another domain or type another name and click **Change address**. Behind the gateway the new route is created
first, Real player IP and Bedrock are carried over, and only then is the old route deleted: a failure before that leaves
the old address working and removes the new one. **The old address stops working**; players then see `Unknown server
address`. No restart is needed. With Bedrock on, download the Floodgate key again: the new address has its own key. In
Direct the button reads **Save address** and only remembers the address for the next switch.

### Servers that are not Minecraft

A server whose egg is not in the settings' egg list shows `The gateway supports Minecraft servers only.` and offers no
mode switch. A server that already uses the gateway when its egg leaves the list keeps working; the page says so, only a
panel admin can switch it to Direct, and the reconcile does not recreate its route if it goes missing.

What the plugin does with allocations: it creates a `127.0.0.1:<port>` allocation on the node for the gateway; in Gateway
only that allocation becomes the primary and the public one is released; going back to Direct removes the loopback
allocation. Switching back to Direct or Both takes the old public address (IP and port) if it is still free, otherwise
a free port on the same IP, then the old port on another IP, then the lowest free one, and says so in the confirm step.
Loopback addresses (`127.0.0.0/8`, `::1`) are never taken as public.

### Ports 1024 and below

Ports 1024 and below cannot use the gateway modes: Pterodactyl refuses an allocation on those ports, Pelican one below
1024, and both plugins keep the same rule. The switch is refused with `Port <n> cannot use the gateway: the panel allows
no 127.0.0.1 allocation on port 1024 or below. Give the server a primary port above 1024 first.` Such a server stays on
Direct; the reconcile reports the same sentence if a gateway server's primary port is moved that low.

### Real player IP

Offered in Gateway only. It makes the route send a PROXY protocol header, so the server must expect one:

- Paper: `proxies.proxy-protocol: true` in `config/paper-global.yml`.
- Velocity: `haproxy-protocol` in `velocity.toml`.

The plugin does not edit server files. Until the server is set, nobody can connect through the gateway.

**Leaving Gateway only with Real IP on:** the switch turns Real IP off at the gateway, but the server still expects PROXY
headers. Turn off proxy-protocol in the server's config **before** you restart, or nobody can connect, neither direct nor
through the gateway. The confirm step and the notice say the same.

### Bedrock and Floodgate

Switch Bedrock players on on the Gateway page, then:

1. Download the Floodgate key from the Gateway page (`key.pem`).
2. Install Floodgate on the server and put the key at `plugins/floodgate/key.pem`.
3. If the server runs an older Minecraft Java version than the newest one, also install the
   [ViaVersion](https://modrinth.com/plugin/viaversion) plugin (Paper/Spigot). DYLARIS' Geyser always speaks the newest
   Java version (currently 26.3); without ViaVersion, Bedrock players on an older server see `Outdated server! I'm still
   on 1.21.11` (with the server's version).
4. Restart the server.

A real Bedrock join was proven on 2026-11-17: Bedrock client 1.26.65 through DYLARIS' Geyser 2.12.1-b1304 to Paper
1.21.11 with Floodgate 2.2.5 and ViaVersion 5.12.2, through a gateway address made by the plugin.

## Reconcile

The plugin compares the panel with the provider every 5 minutes (`dylaris-gateway:reconcile`, scheduled with
`withoutOverlapping`, and under the same cache lock as every mode change). It:

- removes the route and state of a server that was deleted in the panel,
- retargets the route and the loopback allocation when the server's primary port changed,
- follows a server that moved to another node, or, if that node has no link, shows `This node is not connected to the
  gateway.` with the next step (connect the node, or move the server) and leaves the route alone,
- recreates a route that is missing at the provider,
- sets Real player IP at the provider back to what the page shows, if a call that got no answer left them apart,
- says so on the server page when a Gateway only server holds a public allocation (players could still join it
  directly), and changes nothing,
- leaves provider routes it did not create alone. A Direct server keeps its address name for a later switch; a route
  under that name on a link that is not one of the plugin's nodes (made in the dashboard) is never deleted.

Mode changes, Real player IP and Bedrock take the same lock as the reconcile. If a run holds it the change is refused
with `The gateway is being updated, try again in a moment.`

Run it by hand with `php artisan dylaris-gateway:reconcile`. It prints a summary, then one line per fixed server and per
error:

```
Routes: 0 created, 0 deleted, 0 retargeted. Errors: 0.
```

## Rate limits

The provider limits a key: 120 reads and 30 writes per minute, 10 link kits per hour, plus the key's own rate (60 per
minute by default). A reconcile reads the route list once per run, not once per server. When the provider answers 429 the
plugin honours `Retry-After` (60 seconds if the header is missing): the run stops, and later runs make no provider call
until the window has passed. The command then prints `The provider asked to wait N s; the run stopped.` On the Gateway
page a refused call shows the provider's own sentence; try again a little later. While the window runs, the page makes no
provider call either and says `The gateway provider asked to wait a moment. Try again shortly.` The page keeps its status
line for 30 seconds and its suggested address name for 10 minutes, so page views do not use up the read limit. The
domain list is read from the panel's database; the provider is asked about once an hour for it, plus Refresh;
after a failed sync at most every 5 minutes (longer when the provider's Retry-After says so), and only one request
syncs at a time.
