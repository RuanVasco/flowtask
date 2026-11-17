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

use CommonDBRelation;
use ITILCategory;

/**
 * Links a workflow to the ticket categories that start it (spec 001, AC-3).
 * A category belongs to at most one workflow.
 */
class Workflow_ITILCategory extends CommonDBRelation
{
    public static ?string $itemtype_1 = Workflow::class;

    public static ?string $items_id_1 = 'plugin_flowtask_workflows_id';

    public static ?string $itemtype_2 = ITILCategory::class;

    public static ?string $items_id_2 = 'itilcategories_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow category', 'Workflow categories', $nb, 'flowtask');
    }

    /**
     * `item_purge` hook on categories: drop the link instead of resetting it to 0,
     * as `itilcategories_id` is unique.
     */
    public static function purgeForCategory(ITILCategory $category): void
    {
        (new self())->deleteByCriteria(['itilcategories_id' => $category->getID()], true);
    }
}
