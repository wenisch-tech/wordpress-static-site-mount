#!/usr/bin/env bash
# Integration test environment: real WordPress (block theme) + MariaDB + the plugin + fixture docs site.
# Usage: ./run.sh up | down | wp <wp-cli args>
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; PLUGIN="${STASIMO_SRC:-$(dirname "$HERE")}"
N=stasimotest
case "${1:-up}" in
down) docker rm -f $N-wp $N-db >/dev/null 2>&1 || true; docker network rm $N >/dev/null 2>&1 || true; docker volume rm $N-html >/dev/null 2>&1 || true ;;
wp) shift; docker run --rm -u 33:33 --network $N -e WORDPRESS_DB_HOST=$N-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
      -v $N-html:/var/www/html -v "$PLUGIN":/var/www/html/wp-content/plugins/stasimo-static-site-mount:ro -v "$HERE/fixtures":/fixtures:ro \
      wordpress:cli-php8.3 wp --path=/var/www/html "$@" ;;
up)
  "$0" down
  docker network create $N >/dev/null; docker volume create $N-html >/dev/null
  docker run -d --name $N-db --network $N -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
  for i in $(seq 1 40); do docker exec $N-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break; sleep 2; done
  docker run -d --name $N-wp --network $N -p 8089:80 -e WORDPRESS_DB_HOST=$N-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
    -v $N-html:/var/www/html -v "$PLUGIN":/var/www/html/wp-content/plugins/stasimo-static-site-mount:ro -v "$HERE/fixtures":/fixtures:ro wordpress:php8.3-apache >/dev/null
  for i in $(seq 1 40); do curl -s -o /dev/null http://localhost:8089/ && break; sleep 2; done
  "$0" wp core install --url=http://localhost:8089 --title="Stasimo Test" --admin_user=admin --admin_password=admin --admin_email=a@example.invalid --skip-email
  "$0" wp theme activate twentytwentyfive
  "$0" wp plugin activate stasimo-static-site-mount
  "$0" wp option update permalink_structure '/%postname%/'
  "$0" wp rewrite flush --hard ;;
esac
