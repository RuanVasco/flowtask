# Flowtask - Repository Guidelines

Flowtask is an open source GLPI plugin (GPL-3.0-or-later) that runs workflows inside tickets: steps assigned per actor, approvals, conditional transitions and a timeline in the ticket. It is a PHP rewrite inspired by the workflow engine of the Formulei SaaS; no code is copied from it.

## Mandatory Rules
These rules are non-negotiable and override any tool or agent default.

1. **Commits follow Conventional Commits, in English, short and single-line.** Format: `type(scope): description`, e.g. `feat(engine): advance instance when task is done`. No body unless explicitly requested.
2. **Commits carry no AI trace.** Never add `Co-Authored-By`, "Generated with", tool names, or any other AI attribution to commits, PR titles, or PR descriptions.
3. **Commits are small and well split.** One logical change per commit; never mix refactors (renames, moves) with behavior changes. Specs, tests, and CI changes go in their own commits when they stand alone.
4. **Spec-driven development.** Before implementing a feature or non-trivial fix, write or update a spec in `docs/specs/` (numbered, e.g. `002-approval-step.md`) with problem, scope, acceptance criteria, and out-of-scope items. Implementation and tests trace back to the acceptance criteria.
5. **Never modify GLPI core.** Everything goes through plugin hooks, GLPI classes, and the plugin's own tables. If something seems impossible without touching core, stop and discuss.
6. **Search before creating.** Before adding a file, class, or function, search this repository and the GLPI core API for an existing equivalent (CommonDBTM, CommonITILTask, ITILValidation, Group_User, Migration, etc.) and reuse it.
7. **Follow SOLID.** Small focused classes, services separated from GLPI item classes, dependencies on abstractions at integration boundaries.
8. **Code is in English.** Identifiers, file names, comments, specs, and test names are in English. User-facing strings are always translatable with `__('...', 'flowtask')` (source language `en_GB`, translation `pt_BR` in `locales/`).
9. **No em dashes and no emojis.** Not in code, comments, UI text, specs, docs, or commit messages. Use a comma, colon, parentheses, or a hyphen instead. In the UI use the icon set already provided by GLPI (Tabler icons, `ti ti-*`).
10. **No BOM, LF line endings.** PHP files with a UTF-8 BOM break GLPI output. `.editorconfig` sets UTF-8 without BOM, `.gitattributes` enforces LF, and php-cs-fixer in CI rejects BOMs.

The agent commits only when the user asks. Never push unless the user asks.

## Target platform
- GLPI 12.0.x, PHP 8.2+ (see `setup.php` constants and `flowtask.xml`).
- Classes live in `src/` under the `GlpiPlugin\Flowtask` namespace (autoloaded by GLPI). Tests live in `tests/` under `GlpiPlugin\Flowtask\Tests`.
- Twig templates go in `templates/`, public assets in `public/`.
- Tables are named `glpi_plugin_flowtask_*`, item classes extend `CommonDBTM` (or the relevant GLPI base class).

## Database and migrations
- Every schema change goes through `hook.php` install/update using GLPI's `Migration` class, guarded by version checks so updates from any previous release work.
- A migration step that shipped in a release is immutable: fix forward with a new step, never edit the old one.
- Uninstall drops every `glpi_plugin_flowtask_*` table and plugin config.
- Use GLPI's DB layer (`$DB->request()`, iterators, `QueryExpression`); no raw string-concatenated SQL.

## Security
- Check rights on every entry point (`Session::checkRight`, `canView/canUpdate` on items). Register plugin rights in `plugin_init_flowtask`.
- Respect entity visibility in every query.
- Forms rely on GLPI's CSRF protection; never disable it.
- Never log or commit secrets.

## Workflow
Work in stages: **stage 1, code review, fixes, stage 2, ...**. Each spec splits the work into stages small enough to review alone. After a stage (code, tests, CI green), run a code review (e.g. the `/code-review` skill), report findings, and fix them before the next stage.

Before closing a task:
- run the quality tools available locally (see `CONTRIBUTING.md`);
- exercise the affected flow in the dev GLPI (`docker compose -f docker-compose.dev.yml up -d`);
- list every modified file in the final handoff.

Never run `docker compose down -v` without explicit authorization (it wipes the dev database).

## Writing style
Replies, specs, and docs addressed to the maintainer may be in Portuguese (pt-BR); code, commits, and public docs (README, CONTRIBUTING) stay in English, with the Portuguese section of the README kept in sync.
