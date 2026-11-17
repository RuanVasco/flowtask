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

namespace GlpiPlugin\Flowtask\Http;

use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Html;

/**
 * Form page of the items that belong to a version (steps and transitions).
 *
 * GLPI's generic form controller checks the rights of a new item before it
 * knows its parent, which a CommonDBChild needs, so these items keep a
 * front/*.form.php script that delegates here.
 */
final class VersionItemForm
{
    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    public static function handle(Node|Transition $item, array $get, array $post): void
    {
        $id = Fields::int($post, 'id');

        if (isset($post['add'])) {
            $item->check(-1, CREATE, $post);
            if ($item->add($post) === false) {
                Html::back();
            }

            Html::redirect(self::getWorkflowURL(Fields::int($post, $item::$items_id)));
        } elseif (isset($post['update'])) {
            $item->check($id, UPDATE);
            $item->update($post);
            Html::back();
        } elseif (isset($post['purge'])) {
            $item->check($id, PURGE);
            $versions_id = Fields::int($item->fields, $item::$items_id);
            $item->delete(['id' => $id], true);
            Html::redirect(self::getWorkflowURL($versions_id));
        }

        $id    = isset($get['id']) ? Fields::int($get, 'id') : -1;
        $input = [$item::$items_id => Fields::int($get, $item::$items_id)];
        $item->check($id, READ, $input);

        Html::header($item::getTypeName(1), '', 'config', Workflow::class);
        /** @phpstan-ignore argument.type (display() takes -1 for a new item, and extra keys are passed to showForm()) */
        $item->display(['id' => $id] + $input);
        Html::footer();
    }

    /**
     * Steps and transitions are edited from their workflow, which shows the
     * current version.
     */
    private static function getWorkflowURL(int $versions_id): string
    {
        $version = new WorkflowVersion();
        if (!$version->getFromDB($versions_id)) {
            return Workflow::getSearchURL();
        }

        return Workflow::getFormURLWithID(Fields::int($version->fields, WorkflowVersion::$items_id));
    }
}
