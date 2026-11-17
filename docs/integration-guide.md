# Build your own integration

This guide is for you if you want to connect the DYLARIS Managed Gateway to a panel other than Pelican or Pterodactyl
2.0, or to your own hosting system. It describes what the two plugins in this repository do, step by step, and why, so
you can do the same.

The endpoint reference (every field, every status code) lives on dylaris.com:
**[https://dylaris.com/docs/gateway-api](https://dylaris.com/docs/gateway-api)**. This guide only names which endpoints
each step uses. All of them are under `https://panel.dylaris.com/api/external/gateway/`.

## Reuse the reference implementation

`core/src` is a framework-free PHP 8.3 library (`Dylaris\GatewayPanel\`, its only dependency is Guzzle) that holds all
of the gateway logic both plugins share. If your system is PHP, you can use it as is and write two small classes:

| Interface | What you implement | Examples in this repo |
|---|---|---|
| `StateStore` | Persist one `ServerState` per server and one `NodeState` per node (get, put, delete, all). | `pelican/.../EloquentStateStore.php`, `pterodactyl/.../PteroStateStore.php`, `core/src/Testing/InMemoryStateStore.php` |
| `PanelAdapter` | Read a server and its allocations; find or create, assign, unassign, delete allocations; set the primary one; find a free public allocation. | `pelican/.../PelicanPanelAdapter.php`, `pterodactyl/.../PteroPanelAdapter.php`, `core/src/Testing/FakePanel.php` |

Then wire them together:

- `Provider\ProviderClient` - one method per provider call, Bearer auth, 10 s connect and 20 s request timeout, every
  failure as a `Provider\ProviderException` with `status`, `errorCode`, `userMessage` and `retryAfter`.
- `ModeService` - switches one server between the modes, with undo on failure.
- `Reconciler` - brings every known server back in line with the panel and the provider.
- `StatusService` - what a server page shows (route active, link online, player counts).
- `AddressLabel` - turns a server name into an address name the hoster domain accepts.

In another language, read those files as the specification. They are short, and the tests in `core/tests` (with the
recorded provider responses in `core/tests/fixtures/provider`) show the expected behaviour for every case below.

## The objects

| Object | What it is | Endpoints |
|---|---|---|
| Account | Your plan and what the API key may do: `product` (`managed` for the Managed Gateway), `managed`, `suspended`, `scopes`, and `limits` (`maxLinks`, `maxRoutes`). | `GET account` |
| Regions | The regions you can connect a node to (`region`, `wildcard`, `online`) and the **hoster domains** addresses live under, each with a `validation` rule (`letters`, `alphanumeric` or `dns`). | `GET regions` |
| Link kit | One per node. Minting returns a `link_id` and a `link_key`; the key is shown **once**. The list shows each kit's `online` state and the kit `limit`, `used` and `retiring` counts. | `GET link-kits`, `POST link-kits`, `DELETE link-kits/{linkId}` |
| Route | One per game server address: `domain` (address name plus hoster domain), the `link_id` it runs through, `target_ip` and `target_port` on the node, `proxy_protocol`, and the Java and Bedrock edition state. | `GET routes`, `POST routes`, `DELETE routes/{domain}`, `PATCH routes/{domain}/proxy-protocol`, `PUT routes/{domain}/bedrock`, `GET routes/{domain}/floodgate-key` |
| Domain check | Whether an address is free, and if not, why (`taken`, `blocked`, `invalid`, `not_hosted`, `unverified`). | `GET check-domain?domain=...` |
| Players | Current Java and Bedrock players per route domain. | `GET players` |

The provider adds fields over time but does not rename or remove them. Read only the keys you need and ignore the rest
(`core/src/Provider/Dto.php` does exactly that).

## Lifecycle

### 1. Check the key

Call `GET account` when the operator saves the settings. The plugins accept the key only if `product` is `managed`,
`managed` is true, `suspended` is false and `scopes` contains both `gateway.read` and `gateway.write`, and they say which
of these is missing.

Use **one API key per installation**. Two systems sharing a provider account each believe the account's routes are
theirs to clean up.

Store the key encrypted, and never send a saved key to a URL other than the one it was saved for: if someone changes
the provider URL, ask for the key again.

### 2. Connect a node: mint a link kit

1. `GET regions`; offer only regions with `online: true`.
2. `POST link-kits` with a `name` (the plugins use `Pelican: <node name>` / `Pterodactyl: <node name>`, at most 64
   characters) and the chosen `region`.
3. Store the returned `link_id` with the node. **Do not store the `link_key`**: show it once, inside the compose file
   below, and forget it. To get a new key, revoke the kit and mint a new one.
4. Guard the mint with a per-node lock (the plugins use 60 s) and re-check that the node is not already connected inside
   it, so a double click or a second tab does not mint two kits. Kits are limited per plan and per hour.

The region is fixed for the life of the kit. Changing it means revoking the kit and minting a new one.

### 3. Run the link on the node

The link container opens an outbound tunnel to the gateway and forwards each player connection to the target the route
names. The plugins generate this compose file (see `snippet()` in `pelican/dylaris-gateway/src/Livewire/NodeGateway.php`
and `pterodactyl/dylaris-gateway/src/Http/NodeGatewayController.php`):

```yaml
services:
  link:
    image: ghcr.io/dylaris-dev/gateway-link:latest
    network_mode: host
    restart: unless-stopped
    stop_grace_period: 6h10m
    mem_limit: 4g
    environment:
      LINK_KEY: "<link_key from POST link-kits>"
      CORE_URL: "https://panel.dylaris.com"
      LINK_DRAIN_TIMEOUT: "6h"
      LINK_ALLOWED_TARGETS: "172.18.0.1"
      LOCAL_HOST: "172.18.0.1"
      # Host networking: keep the link's health endpoint off the network.
      LINK_PORT: "127.0.0.1:25540"
      GOMEMLIMIT: "3400MiB"
```

- `CORE_URL` is the provider URL the operator configured.
- `LINK_ALLOWED_TARGETS` and `LOCAL_HOST` are the **target host**: the private address on the node where game servers are
  reachable. For Wings that is its Docker interface (`172.18.0.1` by default, `docker.network.interface` in the Wings
  config), because Wings binds a `127.0.0.1` allocation to that interface rather than to loopback. Use your own system's
  equivalent. The plugins store it per node (`NodeState::$targetHost`).
- Values are JSON-quoted, which YAML reads as double-quoted strings, so a key or URL cannot break the file.
- The file holds the link key: tell the operator to keep it private (mode 600).

The kit shows `online: true` in `GET link-kits` once the link is connected. Until then, show the node as "link offline".

### 4. Put a server behind the gateway: create a route

For a switch from Direct to Both or Gateway only, `ModeService::apply()` does this, in this order:

1. **Choose the address.** Take the first hoster domain from `GET regions`, reduce the server name to what its
   `validation` allows (`AddressLabel::candidates()`), and ask `GET check-domain` until one is available; fall back to a
   few random letter names. A name the user typed is checked the same way, and a refusal is shown with the `reason`.
   Remember the address with the server, also after it goes back to Direct, so it keeps its name.
2. **Make the server reachable on the target host.** Give the server an allocation on `127.0.0.1` with the **same port
   as its primary allocation** (Wings maps every allocation of a server to the primary port inside the container). The
   panels allow no such allocation on port 1024 or below, so those servers stay on Direct.
3. **`POST routes`** with `linkId` (the node's kit), `subdomain` (the address name), `hosterDomain`, `targetHost` (the
   node's target host) and `targetPort` (the primary port). Posting an existing domain retargets it, so the same call
   repairs a route later.
4. For **Gateway only**, make the loopback allocation the primary and release the public one, remembering its IP and
   port so a later switch back can take them again.
5. Tell the user to restart the server: allocation changes take effect on a restart.

Switching back:

- **Gateway only to Both or Direct:** if Real player IP is on, turn it off first (`PATCH .../proxy-protocol`), because
  direct players send no PROXY header. Take a public allocation back as primary (the remembered one if free). If the
  primary port changed, move the loopback and retarget the route to the new port.
- **Both or Gateway only to Direct:** `DELETE routes/{domain}` (a 404 means it is already gone, which is fine), then
  remove the loopback allocation.

Optional switches on a route:

- **Real player IP** (`PATCH routes/{domain}/proxy-protocol`): the plugins offer it in Gateway only only. In Both it
  would break every direct player, because the server then expects a PROXY header on every connection.
- **Bedrock** (`PUT routes/{domain}/bedrock` with `java` and `bedrock`): needs a gateway address. The server needs
  Floodgate with the key from `GET routes/{domain}/floodgate-key`.

### 5. Show status

`StatusService::forServer()` uses `GET link-kits` (is the node's link online), `GET routes` (is there a route for this
address **on this node's link**) and `GET players`. Cache the result; the plugins keep it 30 s, and the suggested address
name 10 minutes, so page views do not use up the read limit.

### 6. Reconcile

Panels change things the gateway does not see. Run a reconcile on a schedule (the plugins: every 5 minutes, and the
Pterodactyl extension also after server operations). `Reconciler::run()` reads `GET routes` and `GET link-kits` **once
per run**, not once per server, then for every server it has state for:

- **Server deleted in the panel:** delete its route and loopback allocation, forget the state.
- **Primary port changed:** create the loopback for the new port, retarget the route (`POST routes`), release the old
  loopback.
- **Server moved to another node:** retarget the route to the new node's link; if that node has no link, report it and
  leave the route alone.
- **Route missing at the provider:** create it again.
- **Real player IP differs from the stored value** (a call that got no answer): set it back at the provider.
- **Gateway only server holding a public allocation:** report it, change nothing. The reconcile never changes a mode.
- **Direct server with leftovers** (a half-finished switch): remove the loopback, and its route if it is on one of
  your nodes' links.

Clean up on delete: when your system deletes a server or disconnects a node, delete the route
(`DELETE routes/{domain}`), and revoke the node's kit (`DELETE link-kits/{linkId}`, a 404 is fine). The plugins refuse to
disconnect a node while a server on it is still in a gateway mode.

**Never touch routes you did not create.** The provider account may hold routes made in the DYLARIS dashboard or by
another system. The reconcile only acts on routes whose domain belongs to a server it has state for, and for a Direct
server's kept name only when the route is on one of its own nodes' links.

## Errors

Every error response is JSON with `success: false`, a machine-readable `code` and a human-readable `error` sentence you
can show as is. Examples from `core/tests/fixtures/provider`:

```json
{"success": false, "code": "target_not_allowed", "error": "This target is not allowed. Use one of your servers (mc-<uuid>) or a private address; public targets need to be enabled for your account."}
```

| Status / code | Meaning | What the plugins do |
|---|---|---|
| 429 `rate_limited` | The key's rate limit was hit. The response carries `Retry-After` in seconds. | Stop the run, store "busy until now + Retry-After" (60 s if the header is missing), and make **no** provider call, from the reconcile or from page views, until it has passed. |
| `kit_retiring` | The node's link is being replaced; no new routes on it. | Show the sentence ("Move the server to another node first."). |
| `target_not_allowed` | The route's target is not a private address the account may use. | Show the sentence; check the node's target host. |
| 404 `not_found` | The route or kit does not exist. | On delete or revoke: treat as done. Otherwise show the sentence. |
| 401 / 403 | Key invalid, or missing a scope. | Show the sentence; the operator fixes the key. |
| 5xx, or no answer | Provider or network problem. | See undo below. |

The provider's limits per key, at the time of writing: 120 reads and 30 writes per minute, 10 link kits per hour, plus
the key's own rate. Read the reference for current values.

## Idempotency, undo and locking

The plugins follow these rules, and they are the reason a failed switch leaves nothing half-done:

- **Undo on failure.** `ModeService` registers an undo step after each change (allocation assigned, primary moved,
  route created, Real IP turned off) and runs them in reverse when a later step fails, then reports the error.
- **A timeout or a 5xx may still have done the work.** When `POST routes` gets no answer or a 5xx, the route may exist
  anyway, so its undo (`DELETE routes/{domain}`) runs too. A **4xx** created nothing, and the address may belong to
  someone else, so it is not deleted.
- **Safe to repeat.** `POST routes` on an existing domain retargets it, and a 404 on delete counts as done, so every
  reconcile step can run again and finishes what a failed run left.
- **One change at a time.** The reconcile and every mode, Real IP and Bedrock change take **one shared lock** (the
  plugins use a cache lock: 600 s for a run, 300 s for a change). A change that finds it taken is refused with "try
  again in a moment" instead of racing the run.
- **Never write back an old row.** The reconcile writes its changes onto the state as it is now, not onto the copy it read
  at the start, and skips a server whose mode or address changed meanwhile (`Reconciler::persist()`).
- **Re-check before reusing a kept address.** When a Direct server switches back to a gateway mode, check `GET routes`
  first: someone else may have taken the name in the meantime, and posting it would retarget their route.

## Checklist

- [ ] `GET account` checked on save; one key per installation; key stored encrypted; saved key never sent to a new URL.
- [ ] One kit per node, minted under a per-node lock; link key shown once and not stored.
- [ ] Link compose file with the node's target host; values quoted.
- [ ] Loopback allocation on the primary port; ports 1024 and below refused.
- [ ] Route created last on the way in, deleted first on the way out; undo on failure, including after a timeout or 5xx.
- [ ] Real player IP only in Gateway only, and off before the public port comes back.
- [ ] Reconcile on a schedule, one list call per run, under the same lock as changes; only your own routes.
- [ ] `Retry-After` honoured everywhere, including page views.
