#!/usr/bin/env bash
set -euo pipefail

if command -v ddev >/dev/null 2>&1; then
  echo "DDEV already installed."
else
  curl -fsSL https://ddev.com/install.sh | bash
fi

ddev version
