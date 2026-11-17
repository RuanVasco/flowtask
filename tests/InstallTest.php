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

namespace GlpiPlugin\Flowtask\Tests;

use DBmysql;
use Glpi\System\Diagnostic\DatabaseSchemaConsistencyChecker;
use GlpiPlugin\Flowtask\Install\Installer;
use GlpiPlugin\Flowtask\Workflow;
use Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function tableProvider(): iterable
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (array_keys((new Installer($DB))->getTableDefinitions()) as $table) {
            yield $table => [$table];
        }
    }

    #[DataProvider('tableProvider')]
    public function testTableIsCreated(string $table): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->assertTrue($DB->tableExists($table, false));
    }

    #[DataProvider('tableProvider')]
    public function testTableFollowsGlpiSchemaConventions(string $table): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $checker = new DatabaseSchemaConsistencyChecker($DB);

        $this->assertSame([], $checker->getMissingFields($table));
        $this->assertSame([], $checker->getInvalidFieldsTypes($table));
    }

    public function testEveryItemtypeMapsToAPluginTable(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $tables = array_keys((new Installer($DB))->getTableDefinitions());
        $mapped = array_map(static fn(string $itemtype): string => $itemtype::getTable(), Installer::ITEMTYPES);

        sort($tables);
        sort($mapped);
        $this->assertSame($tables, $mapped);
    }

    public function testSuperAdminGetsWorkflowRights(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => 'rights',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['profiles_id' => 4, 'name' => Workflow::$rightname],
        ])->current();

        $this->assertIsArray($row);
        $this->assertSame(ALLSTANDARDRIGHT, (int) $row['rights']);
    }

    public function testInstallIsIdempotent(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        (new Installer($DB))->install(new Migration(PLUGIN_FLOWTASK_VERSION));

        $count = countElementsInTable('glpi_profilerights', ['name' => Workflow::$rightname]);
        $this->assertSame(countElementsInTable('glpi_profiles'), $count);
    }
}
