#!/bin/sh
# Exercise the real Apache/PHP boundary, including Authorization forwarding.
set -eu
image=${1:-tesco-api-api}
container=$(docker run -d --rm --read-only --tmpfs /tmp:mode=1777 \
  --cap-drop ALL --security-opt no-new-privileges:true \
  -p 127.0.0.1::8080 -e DB_PATH=/tmp/smoke.sqlite \
  -e APP_API_TOKEN=runtime-test-token "$image")
trap 'docker stop "$container" >/dev/null' EXIT INT TERM
port=$(docker inspect --format '{{(index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' "$container")
base="http://127.0.0.1:$port"
attempt=0
until curl -fsS "$base/health" >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 50 ]; then
    docker logs "$container"
    exit 1
  fi
  sleep 0.2
done
status=$(curl -sS -o /dev/null -w '%{http_code}' "$base/watchlist")
[ "$status" = 401 ]
body=$(curl -fsS -H 'Authorization: Bearer runtime-test-token' "$base/watchlist")
[ "$body" = '{"products":[]}' ]
curl -fsS "$base/openapi.json" >/dev/null
for path in composer.json var/tesco.sqlite .env src/Bootstrap.php; do
  status=$(curl -sS -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer runtime-test-token' "$base/$path")
  [ "$status" = 404 ]
done
[ "$(docker exec "$container" id -u)" = 33 ]
printf '%s\n' 'Runtime smoke passed: authentication, private paths, read-only filesystem, non-root process.'
