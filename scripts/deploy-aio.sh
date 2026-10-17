#!/bin/bash

# Build this fork and install it into a Nextcloud AIO container.
# Run on the Docker host from a checkout of this repo (only git + docker needed):
#   scripts/deploy-aio.sh [container]   (default: nextcloud-aio-nextcloud)
#
# The installed app is backed up next to this repo before it is replaced.
# Rollback:
#   docker exec <container> rm -rf /var/www/html/custom_apps/memories
#   docker cp <backup-dir> <container>:/var/www/html/custom_apps/memories
#   docker exec <container> chown -R www-data:www-data /var/www/html/custom_apps/memories

set -euo pipefail

NC="${1:-nextcloud-aio-nextcloud}"
APP=/var/www/html/custom_apps/memories
SRC="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP="$(dirname "$SRC")/memories-backup-$(date +%Y%m%d-%H%M%S)"
TMP="$(mktemp -d)"
DIST="$TMP/memories"

occ() { docker exec --user www-data "$NC" php occ "$@"; }

echo "==> Building frontend"
# --init makes Ctrl+C stop the container; --name blocks a second concurrent build
docker run --rm --init --name memories-build-js -v "$SRC":/app -w /app -e HOME=/tmp -u "$(id -u):$(id -g)" \
    -e MEMORIES_WEB_PATH=/custom_apps/memories node:24 \
    sh -c "rm -rf js && npm ci --no-audit --no-fund && npm run build"

echo "==> Building go-vod"
docker run --rm --init --name memories-build-go -v "$SRC/go-vod":/src -v "$TMP":/out -w /src -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e GOCACHE=/tmp/go-cache -e GOPATH=/tmp/go -e CGO_ENABLED=0 -e GOOS=linux -e GOARCH=amd64 golang:1.27 \
    sh -c "go test ./ffmpeg/ && go build -buildvcs=false -ldflags='-s -w' -o /out/go-vod-amd64"

echo "==> Backing up installed app to $BACKUP"
docker cp "$NC:$APP" "$BACKUP"

# Reuse exiftool binaries; they only match for the same upstream version.
installed=$(grep -o '<version>[^<]*' "$BACKUP/appinfo/info.xml" | cut -d'>' -f2)
fork=$(grep -o '<version>[^<]*' "$SRC/appinfo/info.xml" | cut -d'>' -f2)
if [ "$installed" != "$fork" ]; then
    echo "!! Installed version $installed differs from fork $fork; bin-ext may not match." >&2
fi
if [ ! -d "$BACKUP/bin-ext" ]; then
    echo "!! No bin-ext in installed app; run scripts/get-bin-ext.sh first." >&2
    exit 1
fi

mkdir -p "$DIST"
cp -R "$SRC"/{appinfo,l10n,img,js,lib,templates,COPYING} "$SRC"/exiftest* "$BACKUP/bin-ext" "$DIST/"
cp "$TMP/go-vod-amd64" "$DIST/bin-ext/go-vod-amd64"

echo "==> Installing fork $fork into $NC"
occ maintenance:mode --on
trap 'occ maintenance:mode --off' EXIT
docker exec "$NC" rm -rf "$APP"
docker cp "$DIST" "$NC:$APP"
docker exec "$NC" chown -R www-data:www-data "$APP"
# Drop Memories' cached go-vod copy and stop the running one; it restarts on demand
docker exec "$NC" find /tmp -maxdepth 1 -type f -name 'go-vod-*' -delete
docker exec "$NC" pkill -f go-vod- || true
trap - EXIT
occ maintenance:mode --off
occ upgrade # refuses to run in maintenance mode
# Routes are cached in APCu keyed on app versions; restart PHP so new routes apply
echo "==> Restarting $NC"
docker restart "$NC" >/dev/null

rm -rf "$TMP"
echo "==> Done. Backup of the previous app: $BACKUP"
