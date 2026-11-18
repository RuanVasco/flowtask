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

use CommonDBTM;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Flowtask\Enum\VersionStatus;
use GlpiPlugin\Flowtask\Support\Fields;
use ITILCategory;
use Log;
use Session;
use User;

/**
 * A workflow definition. Its graph lives in versions (spec 001), which stay
 * in the background: the form works on the current version.
 */
class Workflow extends CommonDBTM
{
    public static string $rightname = 'plugin_flowtask_workflow';

    public bool $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow', 'Workflows', $nb, 'flowtask');
    }

    public static function getIcon()
    {
        return 'ti ti-route';
    }

    /**
     * Places the list and the form under Setup > Workflows (breadcrumb and menu).
     */
    public static function getSectorizedDetails(): array
    {
        return ['config', self::class];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Diagram::class, $tabs, $options);
        $this->addStandardTab(Node::class, $tabs, $options);
        $this->addStandardTab(Transition::class, $tabs, $options);
        $this->addStandardTab(Workflow_ITILCategory::class, $tabs, $options);
        $this->addStandardTab(WorkflowVersion::class, $tabs, $options);
        $this->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        if (!$this->isNewItem()) {
            $this->showCurrentVersion();
        }

        TemplateRenderer::getInstance()->display('@flowtask/workflow.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);

        return true;
    }

    /**
     * Card with the current version and the actions that apply to it.
     */
    private function showCurrentVersion(): void
    {
        $draft     = $this->getDraft();
        $published = $this->getPublishedVersion();
        $publisher = $published instanceof WorkflowVersion ? Fields::int($published->fields, 'users_id_publisher') : 0;

        TemplateRenderer::getInstance()->display('@flowtask/workflow_current_version.html.twig', [
            'canedit'           => $this->canUpdateItem(),
            'draft'             => $draft,
            'published'         => $published,
            'publisher'         => $publisher > 0 ? User::getFriendlyNameById($publisher) : '',
            'version_form_url'  => WorkflowVersion::getFormURL(),
            'workflow_field'    => WorkflowVersion::$items_id,
            'workflow_id'       => $this->getID(),
            'publish_url'       => $draft instanceof WorkflowVersion ? WorkflowVersion::getPublishURL($draft->getID()) : null,
        ]);
    }

    /**
     * The version the form works on: the draft, or else the published one.
     */
    public function getCurrentVersion(): ?WorkflowVersion
    {
        return $this->getDraft() ?? $this->getPublishedVersion();
    }

    public function getDraft(): ?WorkflowVersion
    {
        return $this->findVersion(VersionStatus::DRAFT);
    }

    public function getPublishedVersion(): ?WorkflowVersion
    {
        return $this->findVersion(VersionStatus::PUBLISHED);
    }

    private function findVersion(VersionStatus $status): ?WorkflowVersion
    {
        $version = new WorkflowVersion();

        return $version->getFromDBByCrit([
            WorkflowVersion::$items_id => $this->getID(),
            'status'                   => $status->value,
        ]) ? $version : null;
    }

    /**
     * A new workflow starts with a draft, and with its category when it was
     * created from the category tab (`_itilcategories_id`).
     */
    public function post_addItem()
    {
        (new WorkflowVersion())->add([WorkflowVersion::$items_id => $this->getID()]);

        $category_id = Fields::int($this->input, '_itilcategories_id');
        $category    = new ITILCategory();
        if ($category_id > 0 && $category->can($category_id, READ)) {
            (new Workflow_ITILCategory())->add([
                'plugin_flowtask_workflows_id' => $this->getID(),
                'itilcategories_id'            => $category_id,
            ]);
        }
    }

    public static function getPostFormAction(string $form_action, bool $action_success): ?string
    {
        // Open the new workflow, its draft is where the work starts.
        return $form_action === 'add' && $action_success
            ? 'form'
            : parent::getPostFormAction($form_action, $action_success);
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => 2,
            'table'         => static::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 3,
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 16,
            'table'    => static::getTable(),
            'field'    => 'comment',
            'name'     => _n('Comment', 'Comments', Session::getPluralNumber()),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'            => 19,
            'table'         => static::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 121,
            'table'         => static::getTable(),
            'field'         => 'date_creation',
            'name'          => __('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 80,
            'table'    => Entity::getTable(),
            'field'    => 'completename',
            'name'     => Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    /**
     * A workflow that has instances cannot be purged (spec 001, AC-15).
     */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && !$this->hasInstances();
    }

    public function pre_deleteItem()
    {
        if ($this->hasInstances()) {
            Session::addMessageAfterRedirect(
                __s('This workflow has instances and cannot be purged. Deactivate it instead.', 'flowtask'),
                false,
                ERROR,
            );

            return false;
        }

        return true;
    }

    public function hasInstances(): bool
    {
        return countElementsInTable(Instance::getTable(), [
            'plugin_flowtask_workflowversions_id' => new QuerySubQuery([
                'SELECT' => 'id',
                'FROM'   => WorkflowVersion::getTable(),
                'WHERE'  => ['plugin_flowtask_workflows_id' => $this->getID()],
            ]),
        ]) > 0;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            WorkflowVersion::class,
            Workflow_ITILCategory::class,
        ]);
    }
}
