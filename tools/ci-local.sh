#!/usr/bin/env bash
#
# Runs the same checks as the GitHub CI (glpi-project/plugin-ci-workflows)
# inside the official GLPI CI images, against a throwaway MySQL.
#
# Usage: tools/ci-local.sh [php-version] [glpi-version]
#   defaults: php 8.3, GLPI 12.0.x
#
# Set FIX=1 to apply rector, php-cs-fixer and licence header fixes instead of
# only checking.

set -euo pipefail

PHP_VERSION="${1:-8.3}"
GLPI_VERSION="${2:-12.0.x}"
FIX="${FIX:-0}"
IMAGE="ghcr.io/glpi-project/githubactions-glpi-apache:php-${PHP_VERSION}-glpi-${GLPI_VERSION}"
DB_IMAGE="ghcr.io/glpi-project/githubactions-mysql:8.0"
NETWORK="flowtask-ci-$$"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Git Bash on Windows: give Docker a Windows path and stop MSYS path rewriting.
if command -v cygpath >/dev/null 2>&1; then
    ROOT="$(cygpath -w "$ROOT")"
    export MSYS_NO_PATHCONV=1
fi

cleanup() {
    docker rm -f "${NETWORK}-db" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$NETWORK" >/dev/null
docker run -d --name "${NETWORK}-db" --network "$NETWORK" --network-alias db --shm-size=1g \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=glpi "$DB_IMAGE" >/dev/null

docker run --rm --network "$NETWORK" -e FIX="$FIX" -v "${ROOT}:/src" "$IMAGE" bash -c '
set -uo pipefail
PLUGIN=/var/www/glpi/plugins/flowtask
cp -r /src "$PLUGIN"
rm -rf "$PLUGIN/vendor" "$PLUGIN/var"
cd "$PLUGIN" && composer install -q --no-interaction

FAILED=0
step() {
    local name="$1"; shift
    if output="$("$@" 2>&1)"; then
        echo "[ok]   $name"
    else
        echo "[FAIL] $name"
        echo "$output" | tail -40 | sed "s/^/       /"
        FAILED=1
    fi
}

if [ "$FIX" = "1" ]; then
    ../../vendor/bin/rector process --no-progress-bar >/dev/null
    PHP_CS_FIXER_IGNORE_ENV=1 php ../../vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --show-progress none >/dev/null
    php ../../bin/console tools:licence_headers_check --no-interaction --plugin=flowtask --fix >/dev/null
    tar -C "$PLUGIN" --exclude=./vendor --exclude=./var --exclude=./composer.lock -cf - . | tar -C /src -xf -
    echo "Fixes applied to the working tree."
fi

step "parallel-lint" ../../vendor/bin/parallel-lint --exclude ./vendor/ --exclude ./node_modules/ --no-progress .
step "php-cs-fixer"  env PHP_CS_FIXER_IGNORE_ENV=1 php ../../vendor/bin/php-cs-fixer check --config=.php-cs-fixer.php --no-interaction --show-progress none
step "phpstan"       ../../vendor/bin/phpstan analyze --memory-limit=2G --no-progress
step "psalm"         ../../vendor/bin/psalm --no-progress
step "rector"        ../../vendor/bin/rector process --dry-run --no-progress-bar
step "eslint"        ../../node_modules/.bin/eslint .
step "twigcs"        ../../vendor/bin/twigcs --config=.twig_cs.dist.php
step "licence"       php ../../bin/console tools:licence_headers_check --no-interaction --plugin=flowtask

cd /var/www/glpi
for _ in $(seq 60); do php -r "try { new mysqli(\"db\", \"root\", \"\", \"glpi\"); } catch (Throwable \$e) { exit(1); }" && break; sleep 2; done
bin/console database:install --no-interaction --db-name=glpi --db-host=db --db-user=root --db-password="" --strict-configuration -q
step "plugin install"  bin/console plugin:install --no-interaction --username=glpi flowtask
step "plugin activate" bin/console plugin:activate --no-interaction flowtask

cd "$PLUGIN"
sed -i "s/ testdox=\"true\"//gI" phpunit.xml
step "phpunit" php ../../vendor/bin/phpunit

cd /var/www/glpi
step "plugin uninstall" bin/console plugin:uninstall --no-interaction --username=glpi flowtask
step "uninstall drops tables" php -r "exit((new mysqli(\"db\", \"root\", \"\", \"glpi\"))->query(\"SHOW TABLES LIKE \\\"glpi_plugin_flowtask_%\\\"\")->num_rows);"

exit $FAILED
'
