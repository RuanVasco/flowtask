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
use GlpiPlugin\Flowtask\Definition\StepConfiguration;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\MissingManagerBehavior;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Support\Fields;
use Group;
use Session;
use User;

/**
 * A step of a workflow version graph. Steps only change while their version
 * is a draft (spec 001, AC-2).
 */
class Node extends CommonDBChild
{
    public static string $itemtype = WorkflowVersion::class;

    public static string $items_id = 'plugin_flowtask_workflowversions_id';

    /** Foreign keys to core items, reset by GLPI when those items are purged. */
    private const REFERENCE_FIELDS = ['users_id', 'groups_id', 'tasktemplates_id'];

    /** Place of the step on the diagram (spec 002), the only change the START step takes. */
    public const POSITION_FIELDS = ['position_x', 'position_y'];

    public static function getTypeName($nb = 0)
    {
        return _n('Step', 'Steps', $nb, 'flowtask');
    }

    public static function getIcon()
    {
        return 'ti ti-circle-dot';
    }

    public function getNodeType(): ?NodeType
    {
        return NodeType::tryFrom(Fields::string($this->fields, 'node_type'));
    }

    public function isStart(): bool
    {
        return $this->getNodeType() === NodeType::START;
    }

    public function getActorLabel(): string
    {
        $actor = ActorType::tryFrom(Fields::string($this->fields, 'actor_type'));

        return match ($actor) {
            null                          => '',
            ActorType::REQUESTER_MANAGER  => sprintf(
                __('%1$s (level %2$d)', 'flowtask'),
                $actor->label(),
                Fields::int($this->fields, 'manager_level'),
            ),
            ActorType::USER               => sprintf(__('%1$s: %2$s'), $actor->label(), User::getFriendlyNameById(Fields::int($this->fields, 'users_id'))),
            ActorType::GROUP              => sprintf(__('%1$s: %2$s'), $actor->label(), Group::getFriendlyNameById(Fields::int($this->fields, 'groups_id'))),
        };
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof WorkflowVersion && !$item instanceof Workflow || $item->isNewItem()) {
            return '';
        }

        // Resolving the current version costs queries: only do it for the count.
        $version = $_SESSION['glpishow_count_on_tabs'] ? WorkflowVersion::forTab($item) : null;
        $count   = $version instanceof WorkflowVersion
            ? countElementsInTable(self::getTable(), [self::$items_id => $version->getID()])
            : 0;

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $version = WorkflowVersion::forTab($item);
        if (!$version instanceof WorkflowVersion) {
            return false;
        }

        self::showForVersion($version);

        return true;
    }

    public static function showForVersion(WorkflowVersion $version): void
    {
        $entries = [];
        foreach (self::getForVersion($version->getID()) as $node) {
            $entries[] = [
                'name'      => $node->getLink(),
                'node_type' => htmlescape($node->getNodeType()?->label() ?? ''),
                'actor'     => htmlescape($node->getActorLabel()),
            ];
        }

        TemplateRenderer::getInstance()->display('@flowtask/version_children.html.twig', [
            'readonly'   => !$version->isDraft(),
            'add_url'    => $version->canUpdateItem()
                ? self::getFormURL() . '?' . http_build_query([self::$items_id => $version->getID()])
                : null,
            'add_label'  => __('Add a step', 'flowtask'),
            'columns'    => [
                'name'      => __('Name'),
                'node_type' => _n('Type', 'Types', 1),
                'actor'     => __('Actor', 'flowtask'),
            ],
            'formatters' => ['name' => 'raw_html', 'node_type' => 'raw_html', 'actor' => 'raw_html'],
            'entries'    => $entries,
        ]);
    }

    /**
     * @return list<self>
     */
    public static function getForVersion(int $versions_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $nodes = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $versions_id], 'ORDER' => 'id']) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $node = new self();
            $node->getFromResultSet($row);
            $nodes[] = $node;
        }

        return $nodes;
    }


    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $version = $this->getItem();
        $types   = [];
        foreach ([NodeType::TASK, NodeType::APPROVAL, NodeType::END] as $type) {
            $types[$type->value] = $type->label();
        }

        $actors = [];
        foreach (ActorType::cases() as $actor) {
            $actors[$actor->value] = $actor->label();
        }

        $behaviors = [];
        foreach (MissingManagerBehavior::cases() as $behavior) {
            $behaviors[$behavior->value] = $behavior->label();
        }

        TemplateRenderer::getInstance()->display('@flowtask/node.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'version_link' => $version instanceof WorkflowVersion ? $version->getLink() : '',
            'is_start'     => $this->isStart(),
            'node_types'   => $types,
            'actor_types'  => $actors,
            'behaviors'    => $behaviors,
        ]);

        return true;
    }

    /**
     * The START step is created with the draft and never edited by hand.
     */
    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && !$this->isStart();
    }

    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && !$this->isStart();
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if (!is_array($input) || !$this->checkVersionIsDraft(Fields::int($input, self::$items_id))) {
            return false;
        }

        if (Fields::string($input, 'node_type') === NodeType::START->value) {
            Session::addMessageAfterRedirect(__s('The start step is created with the draft and cannot be added.', 'flowtask'), false, ERROR);

            return false;
        }

        return $this->withConfiguration($input, $input);
    }

    public function prepareInputForUpdate($input)
    {
        // A step stays in its version; moving it would leave its transitions behind.
        if (is_array($input)) {
            unset($input[self::$items_id]);
        }

        $input = parent::prepareInputForUpdate($input);
        if (is_array($input) && $this->isReferenceReset($input)) {
            return $input;
        }

        if (!is_array($input) || !$this->checkVersionIsDraft(Fields::int($this->fields, self::$items_id))) {
            return false;
        }

        if ($this->isPositionChange($input)) {
            return $input;
        }

        if ($this->isStart() || Fields::string($input, 'node_type') === NodeType::START->value) {
            Session::addMessageAfterRedirect(__s('The start step cannot be edited.', 'flowtask'), false, ERROR);

            return false;
        }

        return $this->withConfiguration($input, array_merge($this->fields, $input));
    }

    /**
     * When the type changes, outgoing transitions with a condition the step
     * can no longer fire are removed (e.g. "Approved" out of a former approval).
     */
    public function post_updateItem($history = true)
    {
        $type = $this->getNodeType();
        if (!in_array('node_type', $this->updates, true) || !$type instanceof NodeType) {
            return;
        }

        $kept = array_map(
            static fn(TransitionCondition $condition): string => $condition->value,
            TransitionCondition::forSource($type),
        );
        $criteria = ['plugin_flowtask_nodes_id_source' => $this->getID()];
        if ($kept !== []) {
            $criteria['NOT'] = ['trigger_condition' => $kept];
        }

        if (countElementsInTable(Transition::getTable(), $criteria) > 0) {
            (new Transition())->deleteByCriteria($criteria, true);
            Session::addMessageAfterRedirect(
                __s('Transitions this step can no longer fire were removed.', 'flowtask'),
                false,
                WARNING,
            );
        }
    }

    /**
     * Transitions cannot point at a removed step.
     */
    public function cleanDBonPurge()
    {
        (new Transition())->deleteByCriteria([
            'OR' => [
                'plugin_flowtask_nodes_id_source' => $this->getID(),
                'plugin_flowtask_nodes_id_target' => $this->getID(),
            ],
        ], true);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $fields Stored fields merged with the input
     *
     * @return array<string, mixed>|false
     */
    private function withConfiguration(array $input, array $fields): array|false
    {
        // An incomplete step is saved: the diagram adds it first and the
        // publication checks it (spec 002, persistence and rules).
        $type_error = StepConfiguration::getTypeError($fields);
        if ($type_error !== null) {
            Session::addMessageAfterRedirect(htmlescape($type_error), false, ERROR);

            return false;
        }

        return array_merge($input, StepConfiguration::normalize($fields));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isPositionChange(array $input): bool
    {
        $changes = $this->getChangedKeys($input);

        return $changes !== [] && array_diff($changes, self::POSITION_FIELDS) === [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private function getChangedKeys(array $input): array
    {
        return array_map(strval(...), array_values(array_filter(
            array_keys($input),
            static fn(int|string $key): bool => $key !== 'id' && !str_starts_with((string) $key, '_'),
        )));
    }

    /**
     * When a user, group or task template is purged, GLPI resets the
     * steps pointing at it through update() (see plugin_flowtask_getDatabaseRelations()).
     * That reset is let through even on published versions: a dangling id
     * would mean the same, and the step is checked again before it runs.
     *
     * @param array<string, mixed> $input
     */
    private function isReferenceReset(array $input): bool
    {
        $changes = array_filter(
            $input,
            static fn(string $key): bool => $key !== 'id' && !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        foreach ($changes as $field => $value) {
            if (!in_array($field, self::REFERENCE_FIELDS, true) || Fields::int($changes, $field) !== 0) {
                return false;
            }
        }

        return $changes !== [];
    }

    private function checkVersionIsDraft(int $versions_id): bool
    {
        if (WorkflowVersion::isDraftId($versions_id)) {
            return true;
        }

        Session::addMessageAfterRedirect(__s('Steps and transitions can only be changed in a draft version.', 'flowtask'), false, ERROR);

        return false;
    }
}
