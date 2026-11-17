# Contributing

## Local environment

Requirements: Docker with Compose.

```bash
docker compose -f docker-compose.dev.yml up -d
```

- GLPI: http://localhost:8080 (default login `glpi` / `glpi`)
- The repository is mounted at `/var/www/glpi/plugins/flowtask`, so code changes are live.
- Enable the plugin in **Setup > Plugins**, or from the container:

```bash
docker compose -f docker-compose.dev.yml exec glpi bin/console plugin:install --username=glpi flowtask
docker compose -f docker-compose.dev.yml exec glpi bin/console plugin:activate flowtask
```

Stop with `docker compose -f docker-compose.dev.yml down` (keep the `-v` flag out unless you want to wipe the database).

## Quality tools

`phpstan`, `php-cs-fixer`, `rector`, `psalm`, `twigcs` and `phpunit` are configured to run from inside a GLPI development checkout (they read `../../vendor` and the GLPI baseline files). CI runs all of them through the official `glpi-project/plugin-ci-workflows`.

To run all of them locally, with Docker only:

```bash
tools/ci-local.sh            # PHP 8.3, GLPI 12.0.x
tools/ci-local.sh 8.5        # another PHP version
FIX=1 tools/ci-local.sh      # apply rector, php-cs-fixer and licence header fixes first
```

It uses the official GLPI CI images and a throwaway MySQL, installs and activates the plugin, runs PHPUnit, then uninstalls it and checks that no `glpi_plugin_flowtask_*` table is left.

## Workflow

- Every feature or non-trivial fix starts with a spec in `docs/specs/` (see `docs/specs/README.md`).
- Commits follow Conventional Commits, in English, single line.
- One logical change per commit.
- Open a PR against `main` linking the spec.
