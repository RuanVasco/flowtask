<?php

/**
 * -------------------------------------------------------------------------
 * Flowtask plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Flowtask.
 *
 * Flowtask is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Flowtask is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Flowtask. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by Ruan Vasconcelos.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/RuanVasco/flowtask
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Flowtask\Install\Installer;

/**
 * Plugin install process
 */
function plugin_flowtask_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    (new Installer($DB))->install(new Migration(PLUGIN_FLOWTASK_VERSION));

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_flowtask_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    (new Installer($DB))->uninstall(new Migration(PLUGIN_FLOWTASK_VERSION));

    return true;
}

/**
 * Foreign keys pointing at core tables. When a referenced item is purged, GLPI
 * resets these fields to 0 (or to the `_replace_by` item).
 *
 * Tables prefixed with `_` are documented only: tickets and categories are
 * unique keys on our side, so their rows are deleted by the `item_purge`
 * hooks registered in setup.php instead of being reset.
 *
 * @return array<string, array<string, list<string>>>
 */
function plugin_flowtask_getDatabaseRelations(): array
{
    if (!Plugin::isPluginActive('flowtask')) {
        return [];
    }

    return [
        'glpi_tickets' => [
            '_glpi_plugin_flowtask_instances' => ['tickets_id'],
        ],
        'glpi_itilcategories' => [
            '_glpi_plugin_flowtask_workflows_itilcategories' => ['itilcategories_id'],
        ],
        'glpi_entities' => [
            'glpi_plugin_flowtask_workflows' => ['entities_id'],
        ],
        'glpi_users' => [
            'glpi_plugin_flowtask_nodes'            => ['users_id'],
            'glpi_plugin_flowtask_instancesteps'    => ['users_id'],
            'glpi_plugin_flowtask_workflowversions' => ['users_id_publisher'],
        ],
        'glpi_groups' => [
            'glpi_plugin_flowtask_nodes'         => ['groups_id'],
            'glpi_plugin_flowtask_instancesteps' => ['groups_id'],
        ],
        'glpi_tasktemplates' => [
            'glpi_plugin_flowtask_nodes' => ['tasktemplates_id'],
        ],
    ];
}
