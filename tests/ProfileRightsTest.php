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

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\ProfileRights;
use GlpiPlugin\Flowtask\Workflow;
use Profile;
use ProfileRight;

final class ProfileRightsTest extends DbTestCase
{
    public function testTabOnlyShowsOnCentralProfiles(): void
    {
        $this->login();

        $central = $this->createItem(Profile::class, ['name' => __FUNCTION__ . 'central', 'interface' => 'central']);
        $helpdesk = $this->createItem(Profile::class, ['name' => __FUNCTION__ . 'helpdesk', 'interface' => 'helpdesk']);

        $tab = new ProfileRights();
        $this->assertNotSame('', $tab->getTabNameForItem($central));
        $this->assertSame('', $tab->getTabNameForItem($helpdesk));
    }

    public function testSavingTheMatrixStoresTheRight(): void
    {
        $this->login();

        $profile = $this->createItem(Profile::class, ['name' => __FUNCTION__, 'interface' => 'central']);

        // Same input shape the rights matrix of the tab posts to Profile::update().
        $this->assertTrue($profile->update([
            'id'                       => $profile->getID(),
            '_' . Workflow::$rightname => [READ . '_0' => 1, UPDATE . '_0' => 1, CREATE . '_0' => 0],
        ]));

        $rights = ProfileRight::getProfileRights($profile->getID(), [Workflow::$rightname]);
        $this->assertSame(READ | UPDATE, (int) $rights[Workflow::$rightname]);
    }
}
