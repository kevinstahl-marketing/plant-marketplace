#!/usr/bin/env bash
set -euo pipefail

NETWORK="ddev-plant-marketplace_default"

if ! docker network inspect "$NETWORK" >/dev/null 2>&1; then
  echo "DDEV network unavailable."
  exit 1
fi

BRIDGE="br-$(docker network inspect "$NETWORK" \
  --format '{{.Id}}' | cut -c1-12)"

add_rule() {
  if ! sudo iptables-legacy -C FORWARD "$@" 2>/dev/null; then
    sudo iptables-legacy -I FORWARD 1 "$@"
  fi
}

add_rule -i "$BRIDGE" -o "$BRIDGE" -j ACCEPT

add_rule -i "$BRIDGE" ! -o "$BRIDGE" -j ACCEPT

add_rule -o "$BRIDGE" \
  -m conntrack --ctstate RELATED,ESTABLISHED \
  -j ACCEPT

echo "DDEV networking configured: $BRIDGE"
