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

use PHPUnit\Framework\TestCase;
use Plugin;

final class SetupTest extends TestCase
{
    public function testPluginIsActive(): void
    {
        $this->assertTrue(Plugin::isPluginActive('flowtask'));
    }

    public function testVersionInformation(): void
    {
        $info = plugin_version_flowtask();

        $this->assertSame('Flowtask', $info['name']);
        $this->assertSame(PLUGIN_FLOWTASK_VERSION, $info['version']);
        $this->assertSame('GPLv3+', $info['license']);
        $this->assertSame(PLUGIN_FLOWTASK_MIN_GLPI_VERSION, $info['requirements']['glpi']['min']);
    }
}
