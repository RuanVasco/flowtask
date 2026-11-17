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
use CommonGLPI;
use DBmysql;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Flowtask\Support\Fields;
use ITILCategory;
use Session;

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

    /** Linking changes the workflow; the category only needs to be visible. */
    public static int $checkItem_2_Rights = self::HAVE_VIEW_RIGHT_ON_ITEM;

    private const WORKFLOW_FIELD = 'plugin_flowtask_workflows_id';

    private const CATEGORY_FIELD = 'itilcategories_id';

    protected function computeFriendlyName()
    {
        return sprintf(
            __('%1$s - %2$s'),
            Workflow::getFriendlyNameById(Fields::int($this->fields, self::WORKFLOW_FIELD)),
            ITILCategory::getFriendlyNameById(Fields::int($this->fields, self::CATEGORY_FIELD)),
        );
    }

    public static function getSectorizedDetails(): array
    {
        return Workflow::getSectorizedDetails();
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow category', 'Workflow categories', $nb, 'flowtask');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof ITILCategory && !$item->isNewItem() && Workflow::canView()) {
            $count = $_SESSION['glpishow_count_on_tabs']
                ? countElementsInTable(self::getTable(), [self::CATEGORY_FIELD => $item->getID()])
                : 0;

            return self::createTabEntry(Workflow::getTypeName(1), $count, $item::class, Workflow::getIcon());
        }

        if (!$item instanceof Workflow || $item->isNewItem()) {
            return '';
        }

        $count = $_SESSION['glpishow_count_on_tabs']
            ? countElementsInTable(self::getTable(), [self::WORKFLOW_FIELD => $item->getID()])
            : 0;

        return self::createTabEntry(ITILCategory::getTypeName(Session::getPluralNumber()), $count, $item::class, ITILCategory::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof ITILCategory) {
            self::showForCategory($item);

            return true;
        }

        if (!$item instanceof Workflow) {
            return false;
        }

        self::showForWorkflow($item);

        return true;
    }

    /**
     * The category is where workflows are managed from (spec 001, definition
     * management rules): show the linked workflow, or offer to create or link one.
     */
    public static function showForCategory(ITILCategory $category): void
    {
        $link       = new self();
        $workflow   = new Workflow();
        $has_link   = $link->getFromDBByCrit([self::CATEGORY_FIELD => $category->getID()]);
        $linked     = $has_link
            && $workflow->getFromDB(Fields::int($link->fields, self::WORKFLOW_FIELD))
            && $workflow->canViewItem();

        $current = $linked ? $workflow->getCurrentVersion() : null;

        TemplateRenderer::getInstance()->display('@flowtask/category_workflow.html.twig', [
            'category'       => $category,
            'workflow'       => $linked ? $workflow : null,
            // Linked to a workflow outside the user's entities: say so, without naming it.
            'hidden_link'    => $has_link && !$linked,
            'link_id'        => $linked ? $link->getID() : 0,
            'can_unlink'     => $linked && $workflow->canUpdateItem(),
            'status_label'   => $current?->getStatus()->label() ?? '',
            'version_number' => $current instanceof WorkflowVersion ? Fields::int($current->fields, 'version_number') : 0,
            'step_count'     => $current instanceof WorkflowVersion ? count(Node::getForVersion($current->getID())) : 0,
            'can_create'     => Workflow::canCreate(),
            'can_link'       => Workflow::canUpdate(),
            'workflow_url'   => Workflow::getFormURL(),
            'workflow_itemtype' => Workflow::class,
            'link_url'       => self::getFormURL(),
            'workflow_field' => self::WORKFLOW_FIELD,
            'category_field' => self::CATEGORY_FIELD,
        ]);
    }


    public static function showForWorkflow(Workflow $workflow): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $entries = [];
        $links   = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::WORKFLOW_FIELD => $workflow->getID()],
        ]);
        foreach ($links as $row) {
            $category = new ITILCategory();
            if (!$category->getFromDB(Fields::int($row, self::CATEGORY_FIELD))) {
                continue;
            }

            $entries[] = [
                'itemtype' => self::class,
                'id'       => Fields::int($row, 'id'),
                'name'     => $category->getLink(['complete' => true]),
            ];
        }

        // A category already linked to any workflow cannot be picked again.
        $used = [];
        foreach ($DB->request(['SELECT' => self::CATEGORY_FIELD, 'FROM' => self::getTable()]) as $row) {
            $used[] = Fields::int($row, self::CATEGORY_FIELD);
        }

        $canedit = $workflow->canUpdateItem();
        $rand    = mt_rand();

        TemplateRenderer::getInstance()->display('@flowtask/workflow_categories.html.twig', [
            'canedit'          => $canedit,
            'form_url'         => self::getFormURL(),
            'workflow_field'   => self::WORKFLOW_FIELD,
            'workflow_id'      => $workflow->getID(),
            'category_field'   => self::CATEGORY_FIELD,
            'dropdown_options' => [
                'entity'      => $workflow->getEntityID(),
                'entity_sons' => $workflow->isRecursive(),
                'used'        => $used,
            ],
            'columns'          => ['name' => ITILCategory::getTypeName(1)],
            'formatters'       => ['name' => 'raw_html'],
            'entries'          => $entries,
            'massive_params'   => [
                'num_displayed' => count($entries),
                'container'     => 'mass' . str_replace('\\', '', self::class) . $rand,
            ],
        ]);
    }

    /**
     * Links are managed from the workflow tab, they have no page of their own.
     */
    public static function getPostFormAction(string $form_action, bool $action_success): ?string
    {
        return 'back';
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if (!is_array($input)) {
            return false;
        }

        $existing = new self();
        if ($existing->getFromDBByCrit([self::CATEGORY_FIELD => Fields::int($input, self::CATEGORY_FIELD)])) {
            $other = new Workflow();
            $other->getFromDB(Fields::int($existing->fields, self::WORKFLOW_FIELD));
            Session::addMessageAfterRedirect(
                htmlescape($other->canViewItem()
                    ? sprintf(__('This category is already linked to the workflow "%s".', 'flowtask'), $other->getName())
                    : __('This category is already linked to a workflow.', 'flowtask')),
                false,
                ERROR,
            );

            return false;
        }

        return $input;
    }

    /**
     * A link is created or removed, never moved to another workflow or category.
     */
    public function prepareInputForUpdate($input)
    {
        if (is_array($input)) {
            unset($input[self::WORKFLOW_FIELD], $input[self::CATEGORY_FIELD]);
        }

        return parent::prepareInputForUpdate($input);
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
