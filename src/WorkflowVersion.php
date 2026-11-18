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

use CommonDBChild;
use CommonGLPI;
use DBmysql;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Flowtask\Definition\DraftInitializer;
use GlpiPlugin\Flowtask\Enum\VersionStatus;
use GlpiPlugin\Flowtask\Support\Fields;
use Log;
use Session;
use User;

/**
 * A version of a workflow graph. Published versions are immutable (spec 001, AC-2).
 */
class WorkflowVersion extends CommonDBChild
{
    public static string $itemtype = Workflow::class;

    public static string $items_id = 'plugin_flowtask_workflows_id';

    public bool $dohistory = true;

    /** Fields that only VersionPublisher changes, through changeLifecycle(). */
    private const LIFECYCLE_FIELDS = ['status', 'version_number', 'users_id_publisher', 'date_publication'];

    private bool $lifecycle_change = false;

    public static function getSectorizedDetails(): array
    {
        return Workflow::getSectorizedDetails();
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow version', 'Workflow versions', $nb, 'flowtask');
    }

    public static function getIcon()
    {
        return 'ti ti-versions';
    }

    protected function computeFriendlyName()
    {
        return sprintf(__('Version %d', 'flowtask'), Fields::int($this->fields, 'version_number'));
    }

    public function getStatus(): VersionStatus
    {
        return VersionStatus::tryFrom(Fields::string($this->fields, 'status')) ?? VersionStatus::DRAFT;
    }

    public function isDraft(): bool
    {
        return $this->getStatus() === VersionStatus::DRAFT;
    }

    public static function isDraftId(int $id): bool
    {
        $version = new self();

        return $version->getFromDB($id) && $version->isDraft();
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Diagram::class, $tabs, $options);
        $this->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Workflow || $item->isNewItem()) {
            return '';
        }

        $count = $_SESSION['glpishow_count_on_tabs']
            ? countElementsInTable(self::getTable(), [self::$items_id => $item->getID()])
            : 0;

        return self::createTabEntry(__('Version history', 'flowtask'), $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Workflow) {
            return false;
        }

        self::showForWorkflow($item);

        return true;
    }

    public static function showForWorkflow(Workflow $workflow): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $entries  = [];
        $versions = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id => $workflow->getID()],
            'ORDER' => 'version_number DESC',
        ]);
        foreach ($versions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $version = new self();
            $version->getFromResultSet($row);

            $publisher = Fields::int($row, 'users_id_publisher');
            $entries[] = [
                'name'             => $version->getLink(),
                'status'           => htmlescape($version->getStatus()->label()),
                'publisher'        => $publisher > 0 ? htmlescape(User::getFriendlyNameById($publisher)) : '',
                'date_publication' => Fields::string($row, 'date_publication'),
            ];
        }

        TemplateRenderer::getInstance()->display('@flowtask/version_children.html.twig', [
            'add_url'          => null,
            'columns'          => [
                'name'             => self::getTypeName(1),
                'status'           => __('Status'),
                'publisher'        => __('Published by', 'flowtask'),
                'date_publication' => __('Publication date', 'flowtask'),
            ],
            'formatters'       => [
                'name'             => 'raw_html',
                'status'           => 'raw_html',
                'publisher'        => 'raw_html',
                'date_publication' => 'datetime',
            ],
            'entries'          => $entries,
        ]);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $workflow  = $this->getItem();
        $publisher = Fields::int($this->fields, 'users_id_publisher');

        TemplateRenderer::getInstance()->display('@flowtask/workflowversion.html.twig', [
            'item'          => $this,
            // A draft is discarded from its workflow, so the back URL still exists.
            'params'        => $options + ['candel' => false],
            'workflow_link' => $workflow instanceof Workflow ? $workflow->getLink() : '',
            'status_label'  => $this->getStatus()->label(),
            'publisher'     => $publisher > 0 ? User::getFriendlyNameById($publisher) : '',
            'publish_url'   => !$this->isNewItem() && $this->canUpdateItem() ? self::getPublishURL($this->getID()) : null,
        ]);

        return true;
    }

    /**
     * Versions are created and discarded from the workflow form, which is
     * the page to go back to.
     */
    public static function getPostFormAction(string $form_action, bool $action_success): ?string
    {
        return 'back';
    }


    /**
     * The version shown by the diagram tab: the version itself, or the
     * current version of a workflow.
     */
    public static function forTab(CommonGLPI $item): ?self
    {
        return match (true) {
            $item instanceof self     => $item->isNewItem() ? null : $item,
            $item instanceof Workflow => $item->isNewItem() ? null : $item->getCurrentVersion(),
            default                   => null,
        };
    }

    public static function getPublishURL(int $id): string
    {
        /** @var array{root_doc: string} $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/flowtask/WorkflowVersion/' . $id . '/Publish';
    }

    /**
     * Published and archived versions are read only (spec 001, AC-2).
     */
    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && $this->isDraft();
    }

    /**
     * Only drafts can be purged; published and archived versions are the
     * history of the workflow.
     */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && $this->isDraft();
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if (!is_array($input)) {
            return false;
        }

        $workflows_id = Fields::int($input, self::$items_id);
        $drafts       = countElementsInTable(self::getTable(), [
            self::$items_id => $workflows_id,
            'status'        => VersionStatus::DRAFT->value,
        ]);
        if ($drafts > 0) {
            Session::addMessageAfterRedirect(__s('This workflow already has a draft version.', 'flowtask'), false, ERROR);

            return false;
        }

        $input['version_number']     = $this->getLastVersionNumber($workflows_id) + 1;
        $input['status']             = VersionStatus::DRAFT->value;
        $input['users_id_publisher'] = 0;
        $input['date_publication']   = null;

        return $input;
    }

    public function post_addItem()
    {
        /** @var DBmysql $DB */
        global $DB;

        (new DraftInitializer($DB))->initialize($this);
    }

    public function prepareInputForUpdate($input)
    {
        // A version stays in its workflow (one draft per workflow).
        if (is_array($input)) {
            unset($input[self::$items_id]);
        }

        if (!$this->lifecycle_change) {
            if (!$this->isDraft()) {
                Session::addMessageAfterRedirect(
                    __s('A published version cannot be modified. Create a draft instead.', 'flowtask'),
                    false,
                    ERROR,
                );

                return false;
            }

            foreach (self::LIFECYCLE_FIELDS as $field) {
                unset($input[$field]);
            }
        }

        return parent::prepareInputForUpdate($input);
    }

    /**
     * Changes the status and publication fields, which regular updates ignore.
     *
     * @param array<string, mixed> $fields
     */
    public function changeLifecycle(array $fields): bool
    {
        $this->lifecycle_change = true;
        try {
            return $this->update(['id' => $this->getID()] + $fields);
        } finally {
            $this->lifecycle_change = false;
        }
    }

    /**
     * Instances keep pointing at the version they started with (spec 001,
     * AC-8), so a version with instances is never removed.
     */
    public function pre_deleteItem()
    {
        if (countElementsInTable(Instance::getTable(), [self::getForeignKeyField() => $this->getID()]) > 0) {
            Session::addMessageAfterRedirect(
                __s('This version has instances and cannot be purged.', 'flowtask'),
                false,
                ERROR,
            );

            return false;
        }

        return true;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            Node::class,
            Transition::class,
        ]);
    }

    private function getLastVersionNumber(int $workflows_id): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'version_number AS last'],
            'FROM'   => self::getTable(),
            'WHERE'  => [self::$items_id => $workflows_id],
        ])->current();

        return is_array($row) ? Fields::int($row, 'last') : 0;
    }
}
