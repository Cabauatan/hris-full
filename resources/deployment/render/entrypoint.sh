#!/bin/sh
set -eu
umask 077

cd /var/www/html
runtime=resources/deployment/render/configure.php
php "$runtime" check

printf '%s\n' \
    'Render Free preview: uploads and generated payslip files are ephemeral and are lost on restart, redeploy, or idle sleep.' \
    'Render Free may sleep while idle: queue delivery, birthdays, Telegram polling, and scheduled tasks pause until the service wakes.' >&2

export PORT="${PORT:-10000}"
export RUN_MIGRATIONS="${RUN_MIGRATIONS:-false}"
export RUN_REVERB=true RUN_QUEUE_WORKER=true RUN_SCHEDULER=true
export RUN_TELEGRAM_POLLER="${RUN_TELEGRAM_POLLER:-false}"
export APP_MAINTENANCE_DRIVER=file APP_TIMEZONE=Asia/Manila
export SESSION_DRIVER=database SESSION_ENCRYPT=true SESSION_SECURE_COOKIE=true
export SESSION_HTTP_ONLY=true SESSION_SAME_SITE=lax SESSION_DOMAIN=null
export SESSION_COOKIE=hris_hosted_session CACHE_STORE=database QUEUE_CONNECTION=database
export DB_QUEUE_RETRY_AFTER=90 FILESYSTEM_DISK=local TRUSTED_PROXIES=
export LOG_CHANNEL=stderr LOG_LEVEL=warning
export BROADCAST_CONNECTION=reverb REVERB_HOST=127.0.0.1 REVERB_PORT=8082 REVERB_SCHEME=http
export REVERB_SERVER_HOST=127.0.0.1 REVERB_SERVER_PORT=8082 REVERB_SERVER_PATH=
export REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none REVERB_SCALING_ENABLED=false
backend_host="$(php "$runtime" hostname)"
frontend_host="$(php "$runtime" frontend-hostname)"
export REVERB_ALLOWED_ORIGINS="$backend_host,$frontend_host"

# Render Secret Files can initially be readable only by root. Stage the public
# CA certificate outside the document root without changing the mounted file.
mkdir -p /run/hris-mysql-ca
cp -- "$MYSQL_ATTR_SSL_CA" /run/hris-mysql-ca/ca.pem
chown root:www-data /run/hris-mysql-ca /run/hris-mysql-ca/ca.pem
chmod 0750 /run/hris-mysql-ca
chmod 0640 /run/hris-mysql-ca/ca.pem
export MYSQL_ATTR_SSL_CA=/run/hris-mysql-ca/ca.pem

mkdir -p bootstrap/cache storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    /tmp/nginx-client-body /tmp/nginx-fastcgi /tmp/nginx-proxy /tmp/nginx-uwsgi /tmp/nginx-scgi
chown -R www-data:www-data bootstrap/cache storage \
    /tmp/nginx-client-body /tmp/nginx-fastcgi /tmp/nginx-proxy /tmp/nginx-uwsgi /tmp/nginx-scgi
chmod -R ug+rwX bootstrap/cache storage

# Supervisor's children share one unprivileged UID, including shutdown signals.
for runtime_stream in /proc/self/fd/1 /proc/self/fd/2; do
    if ! su-exec www-data test -w "$runtime_stream"; then
        chown www-data:www-data "$runtime_stream"
    fi
    su-exec www-data test -w "$runtime_stream"
done

su-exec www-data php "$runtime" check
php "$runtime" nginx
chown root:www-data /etc/nginx/http.d/default.conf
chmod 0640 /etc/nginx/http.d/default.conf
su-exec www-data nginx -e stderr -t
su-exec www-data php-fpm -t

su-exec www-data php artisan package:discover --no-interaction
su-exec www-data php artisan config:cache --no-interaction
su-exec www-data php "$runtime" secure-mail

# Pending migrations are the only optional database mutation performed here.
# No seeders, resets, data import, or APP_KEY regeneration run during build/start.
if [ "$RUN_MIGRATIONS" = true ]; then
    printf '%s\n' 'Explicit RUN_MIGRATIONS=true: applying pending HRIS migrations.'
    su-exec www-data php artisan migrate --force --no-interaction
fi

# Fail before advertising a healthy web service if TLS/database access or the
# migration repository is missing. This command only reads migration status.
su-exec www-data php artisan migrate:status --no-interaction
su-exec www-data php artisan route:cache --no-interaction
su-exec www-data php artisan view:cache --no-interaction
exec su-exec www-data "$@"
