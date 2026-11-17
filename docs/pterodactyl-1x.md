# Pterodactyl 1.x

Pterodactyl 1.x has no plugin system, so there is no DYLARIS Gateway plugin for it. The gateway still works with a 1.x
panel because it does not depend on the panel: it needs the link container on the Wings node and a route that points to
the game server. You manage the address in the DYLARIS dashboard instead of in the panel.

Nothing in this guide was tested against a 1.x panel. The Wings behaviour it relies on was tested with Wings `1.13.4`.

## 1. Run the link container on the node

Run the link with this compose file (the same one the plugins show when you connect a node):

```yaml
services:
  link:
    image: ghcr.io/dylaris-dev/gateway-link:latest
    network_mode: host
    restart: unless-stopped
    stop_grace_period: 6h10m
    mem_limit: 4g
    environment:
      LINK_KEY: "<the link key from your DYLARIS dashboard>"
      CORE_URL: "https://panel.dylaris.com"
      LINK_DRAIN_TIMEOUT: "6h"
      LINK_ALLOWED_TARGETS: "172.18.0.1"
      LOCAL_HOST: "172.18.0.1"
      LINK_PORT: "127.0.0.1:25540"
      GOMEMLIMIT: "3400MiB"
```

Start it with `docker compose up -d` and keep the file private (mode 600): it holds the link key. `172.18.0.1` is Wings'
default Docker interface IP. If your node's Docker network differs, use that interface's IP in `LINK_ALLOWED_TARGETS` and
`LOCAL_HOST` (`docker.network.interface` in the Wings config names the interface).

## 2. Point the server at the link

Wings does not bind a `127.0.0.1` allocation to loopback: it rewrites it to its Docker interface, which is where the link
reaches the server. So the server needs a second allocation on `127.0.0.1` with the same port as its public one. Add it
in the panel (Admin, the node's Allocation tab, assign it to the server) and restart the server. Ports 1024 and below
cannot be used for this.

Then create the address in the DYLARIS dashboard with the target `172.18.0.1` (or your interface IP) and that port, and
manage it there: a mode switch, Real player IP and Bedrock are all done in the dashboard, not in the panel. With the
public allocation released and the `127.0.0.1` one primary, the server is gateway only; keep both for both modes.

Nothing reconciles a 1.x panel with the gateway: if you change the server's port or move it to another node, change the
allocation in the panel and the route in the dashboard yourself.

## Use a Pterodactyl 2.0 panel if you want the plugin

[install-pterodactyl.md](install-pterodactyl.md) covers Pterodactyl 2.0, where the extension does all of this from the
panel.
