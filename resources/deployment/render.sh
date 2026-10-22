#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

usage() {
    cat <<'HELP'
Usage: bash render.sh DOMAIN NEW_OUTPUT_DIRECTORY [ROOT=/srv/hris] [USER=hris]
Render only: no installation, network requests, migrations or service changes.
Prerequisites on target: Linux/systemd, Nginx, PHP 8.5 CLI+FPM with required
Laravel/PDO MySQL extensions, Composer, Node 24/npm, MariaDB, certbot, tar/gzip.

1. Provision server + DNS; allow SSH from admins, public TCP 80/443 only.
   Keep MySQL and Reverb loopback-only. Do not expose 5002/8002/8082.
2. Put source in ROOT/backend and ROOT/frontend. Create non-root USER with
   own group. Own backend by USER; only storage/bootstrap/cache writable.
   Allow Nginx to traverse ROOT and read frontend/dist; keep backend private.
3. Install backend.env as backend/.env (mode 600, owner USER). Set credentials;
   preserve APP_KEY for existing data. New install only: artisan key:generate.
   Generate independent Reverb key/secret with openssl rand -hex 32. Put only
   the public Reverb key in frontend.env -> frontend/.env.production.
4. As USER: composer install --no-dev --prefer-dist --optimize-autoloader
   As USER in frontend: npm ci && npm run build (Node 24).
   In backend: php artisan hris:production-check; review first-install seed
   separately; back up existing data before php artisan migrate --force.
   Then php artisan optimize. Do not run seeders automatically on upgrades.
5. Install php-fpm.conf into /etc/php/8.5/fpm/pool.d/hris.conf; validate with
   php-fpm8.5 -t and reload php8.5-fpm. Stop any old Telegram poller.
6. Initially enable acme.conf in Nginx (remove distribution default), create
   /var/www/letsencrypt, run nginx -t, reload; obtain the first certificate:
   certbot certonly --webroot -w /var/www/letsencrypt -d DOMAIN
   Replace temporary site with nginx.conf, nginx -t, reload. Install a certbot
   renewal deploy hook to reload Nginx; test certbot renew --dry-run.
7. Install systemd files into /etc/systemd/system; systemd-analyze verify the
   installed units, systemctl daemon-reload; enable --now hris-queue.service
   hris-reverb.service hris-scheduler.timer. Enable hris-telegram.service only
   if configured. Reload/restart long-running services after each deployment.
8. Test HTTPS login, WSS, new-login session revocation, email/Telegram receipt,
   two-device interviews, service crash/reboot, backup/restore and load.
   Backups include secrets/HR data: mode 600, encrypt before offsite transfer,
   set reviewed retention. Local backup alone is not disaster recovery.
HELP
}

if [[ ${1:-} == --help || ${1:-} == -h ]]; then usage; exit 0; fi
if [[ $# -lt 2 || $# -gt 4 ]]; then usage >&2; exit 2; fi
domain=$1
output=$2
root=${3:-/srv/hris}
app_user=${4:-hris}
[[ ${#domain} -le 253 && $domain == *.* && $domain != *..* && $domain =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo 'Invalid lowercase DNS hostname.' >&2; exit 2; }
IFS=. read -r -a labels <<< "$domain"
for label in "${labels[@]}"; do
    [[ ${#label} -le 63 && $label =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] || { echo 'Invalid DNS label.' >&2; exit 2; }
done
[[ ! $domain =~ ^[0-9.]+$ ]] || { echo 'Use a DNS hostname, not an IP address.' >&2; exit 2; }
[[ $root =~ ^/[a-zA-Z0-9_/-]+$ && $root != / && $root != *//* && $root != */ && $root != *..* ]] || { echo 'ROOT must be a clean absolute path without spaces.' >&2; exit 2; }
[[ $app_user =~ ^[a-z_][a-z0-9_-]{0,30}$ && $app_user != root ]] || { echo 'Use a non-root Linux service account.' >&2; exit 2; }
[[ ! -e $output && ! -L $output ]] || { echo 'Output must not already exist.' >&2; exit 2; }
template_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
mkdir -m 700 -- "$output"
mkdir -m 700 -- "$output/systemd"
for template in "$template_dir"/*.template "$template_dir"/systemd/*.template; do
    relative=${template#"$template_dir"/}
    target="$output/${relative%.template}"
    sed -e "s|{{DOMAIN}}|$domain|g" -e "s|{{ROOT}}|$root|g" -e "s|{{USER}}|$app_user|g" "$template" > "$target"
    chmod 600 -- "$target"
done
printf 'Rendered configuration in %s. Credentials are intentionally empty. Run --help for setup order.\n' "$output"
