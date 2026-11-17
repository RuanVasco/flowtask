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

To run them locally, clone GLPI (`12.0/bugfixes` branch), run `composer install` in it, and clone or symlink this repository into its `plugins/flowtask` folder.

## Workflow

- Every feature or non-trivial fix starts with a spec in `docs/specs/` (see `docs/specs/README.md`).
- Commits follow Conventional Commits, in English, single line.
- One logical change per commit.
- Open a PR against `main` linking the spec.
