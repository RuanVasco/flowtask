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

namespace GlpiPlugin\Flowtask;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * "Flowtask" tab on profiles, to grant the plugin rights.
 */
final class ProfileRights extends CommonGLPI
{
    public static string $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Flowtask', 'flowtask');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Profile || $item->getField('interface') !== 'central') {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), 0, $item::class, Workflow::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }

        $canedit = Session::haveRightsOr(Profile::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";
        }

        $item->displayRightsChoiceMatrix(
            self::getRightsDefinition(),
            ['canedit' => $canedit, 'title' => self::getTypeName()],
        );

        if ($canedit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }

        echo '</div>';

        return true;
    }

    /**
     * @return list<array{itemtype: class-string<CommonGLPI>, label: string, field: string}>
     */
    public static function getRightsDefinition(): array
    {
        return [
            [
                'itemtype' => Workflow::class,
                'label'    => Workflow::getTypeName(Session::getPluralNumber()),
                'field'    => Workflow::$rightname,
            ],
        ];
    }
}
