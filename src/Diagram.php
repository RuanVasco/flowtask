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
use GlpiPlugin\Flowtask\View\DiagramView;

/**
 * "Diagram" tab of a workflow (its current version) and of a version
 * (spec 002, where it lives).
 */
class Diagram extends CommonGLPI
{
    public static string $rightname = 'plugin_flowtask_workflow';

    public static function getTypeName($nb = 0)
    {
        return __('Diagram', 'flowtask');
    }

    public static function getIcon(): string
    {
        return 'ti ti-sitemap';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Workflow && !$item instanceof WorkflowVersion || $item->isNewItem()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $version = WorkflowVersion::forTab($item);
        if (!$version instanceof WorkflowVersion) {
            echo '<div class="alert alert-info">' . __s('This workflow has no version yet.', 'flowtask') . '</div>';

            return true;
        }

        // The editor and the version actions are on the workflow (spec 002, AC-7).
        if ($item instanceof Workflow) {
            DiagramView::displayForWorkflow($item, $version);
        } else {
            DiagramView::display(DiagramView::getVersionGraphURL($version->getID()));
        }

        return true;
    }
}
