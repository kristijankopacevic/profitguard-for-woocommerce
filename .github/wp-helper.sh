#!/usr/bin/env bash
# Shared wp-cli shim for the CI jobs.
#
# Defined once and sourced, because a `wp()` function declared inside one
# workflow `run:` block does not survive into the next one, and copying it into
# every step is how the container names drift apart.

wp() {
  docker run --rm --network pg-probe --volumes-from pg-wp \
    -e WORDPRESS_DB_HOST=pg-db:3306 \
    -e WORDPRESS_DB_USER=wordpress \
    -e WORDPRESS_DB_PASSWORD=wordpress \
    -e WORDPRESS_DB_NAME=wordpress \
    wordpress:cli-php8.3 wp --path=/var/www/html --allow-root "$@"
}
