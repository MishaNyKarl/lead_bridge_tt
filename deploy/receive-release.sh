#!/usr/bin/env bash
# Installed root-owned; invoked only by the restricted CI SSH key. Receives action.php on stdin.
set -Eeuo pipefail
umask 077
exec 9>/run/lead-bridge-deploy.lock
flock -w 180 9
stage=$(mktemp -d /var/tmp/lead-bridge-release.XXXXXX)
paused=0
installed=0
stopped=0
cleanup() {
  code=$?
  if (( code != 0 && installed )); then
    install -o root -g root -m 644 "$stage/previous.php" /var/www/lead-bridge/action.php.rollback
    mv /var/www/lead-bridge/action.php.rollback /var/www/lead-bridge/action.php
    systemctl reload php8.3-fpm || true
    systemctl restart lead-bridge.service || true
  fi
  if (( code != 0 && stopped )); then systemctl start lead-bridge.service || true; fi
  if (( paused )); then rm -f /var/lib/lead-bridge/deploy.pause; fi
  rm -rf -- "$stage"
  exit "$code"
}
trap cleanup EXIT
head -c 2097153 > "$stage/action.php"
[[ $(wc -c < "$stage/action.php") -lt 2097153 ]]
php -l "$stage/action.php"
expected=$(sha256sum "$stage/action.php" | cut -d ' ' -f 1)
systemctl start lead-bridge-backup.service
cp /var/www/lead-bridge/action.php "$stage/previous.php"
install -o leadbridge -g leadbridge -m 600 /dev/null /var/lib/lead-bridge/deploy.pause
paused=1
# Let the current lead finish. New workers also observe deploy.pause.
touch /var/lib/lead-bridge/dispatch.lock
chown leadbridge:leadbridge /var/lib/lead-bridge/dispatch.lock
exec 8>/var/lib/lead-bridge/dispatch.lock
flock -w 180 8
systemctl stop lead-bridge.service
stopped=1
install -o root -g root -m 644 "$stage/action.php" /var/www/lead-bridge/action.php.new
mv /var/www/lead-bridge/action.php.new /var/www/lead-bridge/action.php
installed=1
systemctl reload php8.3-fpm
rm -f /var/lib/lead-bridge/deploy.pause
paused=0
flock -u 8
systemctl start lead-bridge.service
systemctl is-active --quiet lead-bridge.service
[[ $(sha256sum /var/www/lead-bridge/action.php | cut -d ' ' -f 1) == "$expected" ]]
# Origin check, including TLS verification. Config file contains a public URL, no credentials.
url=$(cat /etc/lead-bridge-health-url)
for attempt in 1 2 3 4 5; do
  if curl -fsS --max-time 15 "${url}?health=1" | python3 -c 'import sys,json; assert json.load(sys.stdin)["status"] == "online"'; then
    echo "Deployed SHA256 $expected"
    installed=0
    exit 0
  fi
  sleep 2
done
exit 1
