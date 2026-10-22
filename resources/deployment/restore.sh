#!/usr/bin/env bash
set -Eeuo pipefail
# Verification never imports SQL. Restore accepts an existing EMPTY database
# configured by a separate target backend/.env and an explicit database name.
# Restored files are staged separately; no live .env/storage is overwritten.
exec "${PHP_BIN:-php8.5}" "$(dirname -- "$0")/backup-tool.php" "$@"
