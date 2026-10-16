#!/usr/bin/env bash
set -euo pipefail

if ! command -v ddev >/dev/null 2>&1; then
  echo "DDEV is not installed."
  exit 1
fi

if ! docker info >/dev/null 2>&1; then
  echo "Docker is unavailable."
  exit 1
fi

ddev start

bash .devcontainer/fix-network.sh

echo "Plant Marketplace development environment ready."
