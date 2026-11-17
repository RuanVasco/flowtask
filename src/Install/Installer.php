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

namespace GlpiPlugin\Flowtask\Install;

use CommonDBTM;
use DBConnection;
use DBmysql;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Migration;
use ProfileRight;

/**
 * Creates, updates and removes the plugin schema and rights.
 *
 * Tables are only created when missing, so running the installer again is
 * safe. Changes to a table that shipped in a release must be added as new
 * guarded steps in update(), never by editing its creation statement.
 */
final readonly class Installer
{
    /** @var list<class-string<CommonDBTM>> */
    public const ITEMTYPES = [
        Workflow::class,
        WorkflowVersion::class,
        Node::class,
        Transition::class,
        Workflow_ITILCategory::class,
        Instance::class,
        InstanceStep::class,
    ];

    public function __construct(private DBmysql $db) {}

    public function install(Migration $migration): void
    {
        foreach ($this->getTableDefinitions() as $table => $columns) {
            if (!$this->db->tableExists($table)) {
                $this->db->doQuery($this->buildCreateTable($table, $columns));
            }
        }

        $this->update();

        $migration->addRight(Workflow::$rightname, ALLSTANDARDRIGHT, ['config' => UPDATE]);
        $migration->executeMigration();
    }

    public function uninstall(Migration $migration): void
    {
        foreach (array_keys($this->getTableDefinitions()) as $table) {
            $migration->dropTable($table);
        }

        foreach (['glpi_displaypreferences', 'glpi_savedsearches', 'glpi_logs'] as $table) {
            $this->db->delete($table, ['itemtype' => self::ITEMTYPES]);
        }

        ProfileRight::deleteProfileRights([Workflow::$rightname]);
        $migration->executeMigration();
    }

    /**
     * Schema changes for releases after 0.1.0 go here, each one guarded so it
     * only runs on databases that still need it.
     */
    private function update(): void {}

    /**
     * @param list<string> $columns
     */
    private function buildCreateTable(string $table, array $columns): string
    {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        $body = implode(",\n  ", array_merge(
            ["`id` int {$sign} NOT NULL AUTO_INCREMENT"],
            array_map(static fn(string $column): string => str_replace('{sign}', $sign, $column), $columns),
        ));

        return "CREATE TABLE `{$table}` (\n  {$body}\n)"
            . " ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";
    }

    /**
     * Columns of every plugin table, `id` excluded. `{sign}` is replaced by the
     * primary key sign option so foreign keys match the referenced ids.
     *
     * @return array<string, list<string>>
     */
    public function getTableDefinitions(): array
    {
        return [
            'glpi_plugin_flowtask_workflows' => [
                "`name` varchar(255) DEFAULT NULL",
                "`entities_id` int {sign} NOT NULL DEFAULT '0'",
                "`is_recursive` tinyint NOT NULL DEFAULT '0'",
                "`is_active` tinyint NOT NULL DEFAULT '0'",
                "`comment` text",
                "`date_creation` timestamp NULL DEFAULT NULL",
                "`date_mod` timestamp NULL DEFAULT NULL",
                "PRIMARY KEY (`id`)",
                "KEY `name` (`name`)",
                "KEY `entities_id` (`entities_id`)",
                "KEY `is_recursive` (`is_recursive`)",
                "KEY `is_active` (`is_active`)",
                "KEY `date_creation` (`date_creation`)",
                "KEY `date_mod` (`date_mod`)",
            ],
            'glpi_plugin_flowtask_workflowversions' => [
                "`plugin_flowtask_workflows_id` int {sign} NOT NULL DEFAULT '0'",
                "`version_number` int {sign} NOT NULL DEFAULT '1'",
                "`status` varchar(20) NOT NULL DEFAULT 'draft'",
                "`users_id_publisher` int {sign} NOT NULL DEFAULT '0'",
                "`date_publication` timestamp NULL DEFAULT NULL",
                "`comment` text",
                "`date_creation` timestamp NULL DEFAULT NULL",
                "`date_mod` timestamp NULL DEFAULT NULL",
                "PRIMARY KEY (`id`)",
                "UNIQUE KEY `unicity` (`plugin_flowtask_workflows_id`,`version_number`)",
                "KEY `status` (`status`)",
                "KEY `users_id_publisher` (`users_id_publisher`)",
                "KEY `date_creation` (`date_creation`)",
                "KEY `date_mod` (`date_mod`)",
            ],
            'glpi_plugin_flowtask_nodes' => [
                "`plugin_flowtask_workflowversions_id` int {sign} NOT NULL DEFAULT '0'",
                "`name` varchar(255) DEFAULT NULL",
                "`node_type` varchar(20) NOT NULL DEFAULT 'task'",
                "`actor_type` varchar(30) DEFAULT NULL",
                "`users_id` int {sign} NOT NULL DEFAULT '0'",
                "`groups_id` int {sign} NOT NULL DEFAULT '0'",
                "`profiles_id` int {sign} NOT NULL DEFAULT '0'",
                "`manager_level` int {sign} NOT NULL DEFAULT '1'",
                "`missing_manager_behavior` varchar(10) NOT NULL DEFAULT 'block'",
                "`tasktemplates_id` int {sign} NOT NULL DEFAULT '0'",
                "`solve_ticket` tinyint NOT NULL DEFAULT '0'",
                "`position_x` int NOT NULL DEFAULT '0'",
                "`position_y` int NOT NULL DEFAULT '0'",
                "`date_creation` timestamp NULL DEFAULT NULL",
                "`date_mod` timestamp NULL DEFAULT NULL",
                "PRIMARY KEY (`id`)",
                "KEY `plugin_flowtask_workflowversions_id` (`plugin_flowtask_workflowversions_id`)",
                "KEY `node_type` (`node_type`)",
                "KEY `users_id` (`users_id`)",
                "KEY `groups_id` (`groups_id`)",
                "KEY `profiles_id` (`profiles_id`)",
                "KEY `tasktemplates_id` (`tasktemplates_id`)",
                "KEY `date_creation` (`date_creation`)",
                "KEY `date_mod` (`date_mod`)",
            ],
            'glpi_plugin_flowtask_transitions' => [
                "`plugin_flowtask_workflowversions_id` int {sign} NOT NULL DEFAULT '0'",
                "`plugin_flowtask_nodes_id_source` int {sign} NOT NULL DEFAULT '0'",
                "`plugin_flowtask_nodes_id_target` int {sign} NOT NULL DEFAULT '0'",
                "`trigger_condition` varchar(20) NOT NULL DEFAULT 'always'",
                "`date_creation` timestamp NULL DEFAULT NULL",
                "`date_mod` timestamp NULL DEFAULT NULL",
                "PRIMARY KEY (`id`)",
                // No parallel branches in v0.1: one outgoing edge per source and condition.
                "UNIQUE KEY `unicity` (`plugin_flowtask_nodes_id_source`,`trigger_condition`)",
                "KEY `plugin_flowtask_workflowversions_id` (`plugin_flowtask_workflowversions_id`)",
                "KEY `plugin_flowtask_nodes_id_target` (`plugin_flowtask_nodes_id_target`)",
                "KEY `date_creation` (`date_creation`)",
                "KEY `date_mod` (`date_mod`)",
            ],
            'glpi_plugin_flowtask_workflows_itilcategories' => [
                "`plugin_flowtask_workflows_id` int {sign} NOT NULL DEFAULT '0'",
                "`itilcategories_id` int {sign} NOT NULL DEFAULT '0'",
                "PRIMARY KEY (`id`)",
                "UNIQUE KEY `itilcategories_id` (`itilcategories_id`)",
                "KEY `plugin_flowtask_workflows_id` (`plugin_flowtask_workflows_id`)",
            ],
            'glpi_plugin_flowtask_instances' => [
                "`tickets_id` int {sign} NOT NULL DEFAULT '0'",
                "`plugin_flowtask_workflowversions_id` int {sign} NOT NULL DEFAULT '0'",
                "`status` varchar(20) NOT NULL DEFAULT 'running'",
                "`stuck_reason` text",
                "`date_end` timestamp NULL DEFAULT NULL",
                "`date_creation` timestamp NULL DEFAULT NULL",
                "`date_mod` timestamp NULL DEFAULT NULL",
                "PRIMARY KEY (`id`)",
                "UNIQUE KEY `tickets_id` (`tickets_id`)",
                "KEY `plugin_flowtask_workflowversions_id` (`plugin_flowtask_workflowversions_id`)",
                "KEY `status` (`status`)",
                "KEY `date_creation` (`date_creation`)",
                "KEY `date_mod` (`date_mod`)",
            ],
            'glpi_plugin_flowtask_instancesteps' => [
                "`plugin_flowtask_instances_id` int {sign} NOT NULL DEFAULT '0'",
                "`plugin_flowtask_nodes_id` int {sign} NOT NULL DEFAULT '0'",
                "`status` varchar(20) NOT NULL DEFAULT 'active'",
                "`itemtype` varchar(255) DEFAULT NULL",
                "`items_id` int {sign} NOT NULL DEFAULT '0'",
                "`users_id` int {sign} NOT NULL DEFAULT '0'",
                "`groups_id` int {sign} NOT NULL DEFAULT '0'",
                "`outcome` varchar(20) DEFAULT NULL",
                "`date_end` timestamp NULL DEFAULT NULL",
                "`date_creation` timestamp NULL DEFAULT NULL",
                "`date_mod` timestamp NULL DEFAULT NULL",
                "PRIMARY KEY (`id`)",
                "KEY `plugin_flowtask_instances_id` (`plugin_flowtask_instances_id`)",
                "KEY `plugin_flowtask_nodes_id` (`plugin_flowtask_nodes_id`)",
                "KEY `status` (`status`)",
                "KEY `item` (`itemtype`,`items_id`)",
                "KEY `users_id` (`users_id`)",
                "KEY `groups_id` (`groups_id`)",
                "KEY `date_creation` (`date_creation`)",
                "KEY `date_mod` (`date_mod`)",
            ],
        ];
    }
}
