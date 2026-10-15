# GitHub Codespaces Development

This project uses Drupal 11, Composer, and DDEV.

## Starting the project

```bash
ddev start
```

## Known issue: DDEV database connection timeout

In GitHub Codespaces, Docker may have conflicting
iptables and iptables-legacy forwarding rules.

Symptoms:
- MariaDB is running.
- DNS resolves `db`.
- Web-to-database TCP connections time out.
- Drupal reports DatabaseConnectionRefusedException.

### Temporary workaround

Identify the project's Docker bridge:

```bash
docker network inspect ddev-plant-marketplace_default \
  --format '{{.Id}}'
```

The bridge interface is typically `br-` followed by
the first 12 characters of the network ID.

Allow traffic between containers on that bridge:

```bash
BRIDGE="br-$(docker network inspect \
  ddev-plant-marketplace_default \
  --format '{{.Id}}' | cut -c1-12)"

sudo iptables-legacy -C FORWARD \
  -i "$BRIDGE" -o "$BRIDGE" -j ACCEPT 2>/dev/null || \
sudo iptables-legacy -I FORWARD 1 \
  -i "$BRIDGE" -o "$BRIDGE" -j ACCEPT
```

Verify:

```bash
ddev exec bash -c 'echo > /dev/tcp/db/3306'
```

This firewall workaround is temporary and may need
reapplication after Codespaces restarts.

## Codespaces reverse proxy

A local-only file named
`web/sites/default/settings.codespaces.php`
contains development reverse-proxy settings.

It is excluded from Git and must not be deployed.

## Production

Do not deploy Codespaces-specific proxy overrides
or Docker firewall workarounds.