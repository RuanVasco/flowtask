#!/usr/bin/env bash

#
# -------------------------------------------------------------------------
# Flowtask plugin for GLPI
# -------------------------------------------------------------------------
#
# LICENSE
#
# This file is part of Flowtask.
#
# Flowtask is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Flowtask is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Flowtask. If not, see <https://www.gnu.org/licenses/>.
# -------------------------------------------------------------------------
# @copyright Copyright (C) 2026 by Ruan Vasconcelos.
# @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
# @link      https://github.com/RuanVasco/flowtask
# -------------------------------------------------------------------------
#

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
mkdir -p "$PLUGIN"
tar -C /src --exclude=./vendor --exclude=./var --exclude=./node_modules --exclude=./public/lib/diagram -cf - . | tar -C "$PLUGIN" -xf -
cd "$PLUGIN" && composer install -q --no-interaction

FAILED=0
step() {
    local name="$1"; shift
    if output="$("$@" 2>&1)"; then
        echo "[ok]   $name  $(echo "$output" | sed "s/\x1b\[[0-9;]*m//g" | grep -v "^[[:space:]]*$" | tail -1 | cut -c1-90)"
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
    tar -C "$PLUGIN" --exclude=./.git --exclude=./vendor --exclude=./var --exclude=./composer.lock --exclude=./node_modules --exclude=./public/lib/diagram -cf - . | tar -C /src -xf -
    echo "Fixes applied to the working tree."
fi

# npm install runs the build (postinstall), as in CI and in the release archive.
step "npm build"     npm install --no-save --no-audit --no-fund
step "vitest"        node_modules/.bin/vitest run
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
step "plugin uninstall" bin/console plugin:uninstall --no-interaction flowtask
step "uninstall drops tables" php -r "exit((new mysqli(\"db\", \"root\", \"\", \"glpi\"))->query(\"SHOW TABLES LIKE \\\"glpi_plugin_flowtask_%\\\"\")->num_rows);"

exit $FAILED
'
