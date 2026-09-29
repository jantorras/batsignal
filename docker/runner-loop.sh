#!/bin/sh
# Runs the check runner once a minute (replaces an external cron job).
cd /var/www/html || exit 1

echo "[runner] Esperant la base de dades..."
until php bin/health.php >/dev/null 2>&1; do sleep 3; done

php bin/migrate.php || echo "[runner] ATENCIÓ: les migracions han fallat, reviseu els logs."

echo "[runner] En marxa."
while true; do
    started=$(date +%s)
    php cron/run_checks.php
    # Optional external heartbeat (e.g. an Uptime Kuma "push" monitor) to watch the watcher.
    if [ -n "${HEARTBEAT_URL:-}" ]; then
        curl -fsS -m 10 "$HEARTBEAT_URL" >/dev/null 2>&1 || echo "[runner] No s'ha pogut enviar el heartbeat."
    fi
    elapsed=$(( $(date +%s) - started ))
    if [ "$elapsed" -lt 60 ]; then
        sleep $(( 60 - elapsed ))
    fi
done
