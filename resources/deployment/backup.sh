#!/usr/bin/env bash
set -Eeuo pipefail
# Before backup: artisan down; stop scheduler timer/service, queue, Telegram,
# Reverb and PHP-FPM writers. Run as the application user. Bring them back only
# after success/review. --quiesced explicitly confirms this maintenance window.
exec "${PHP_BIN:-php8.5}" "$(dirname -- "$0")/backup-tool.php" backup "$@"
