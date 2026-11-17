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
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Support\Fields;
use Session;

/**
 * A directed edge between two steps of the same workflow version.
 * Transitions only change while their version is a draft (spec 001, AC-2).
 */
class Transition extends CommonDBChild
{
    public static string $itemtype = WorkflowVersion::class;

    public static string $items_id = 'plugin_flowtask_workflowversions_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Transition', 'Transitions', $nb, 'flowtask');
    }

    public static function getIcon()
    {
        return 'ti ti-arrow-right';
    }

    protected function computeFriendlyName()
    {
        return sprintf(
            __('%1$s to %2$s', 'flowtask'),
            Node::getFriendlyNameById(Fields::int($this->fields, 'plugin_flowtask_nodes_id_source')),
            Node::getFriendlyNameById(Fields::int($this->fields, 'plugin_flowtask_nodes_id_target')),
        );
    }

    public function getCondition(): ?TransitionCondition
    {
        return TransitionCondition::tryFrom(Fields::string($this->fields, 'trigger_condition'));
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
        /** @var DBmysql $DB */
        global $DB;

        // One query for the step names instead of one per transition end.
        $names = [];
        foreach (Node::getForVersion($version->getID()) as $node) {
            $names[$node->getID()] = $node->getName();
        }

        $entries = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $version->getID()], 'ORDER' => 'id']) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transition = new self();
            $transition->getFromResultSet($row);
            $source = $names[Fields::int($row, 'plugin_flowtask_nodes_id_source')] ?? '';
            $target = $names[Fields::int($row, 'plugin_flowtask_nodes_id_target')] ?? '';

            $entries[] = [
                'link'      => sprintf(
                    '<a href="%s">%s</a>',
                    htmlescape($transition->getLinkURL()),
                    htmlescape(sprintf(__('%1$s to %2$s', 'flowtask'), $source, $target)),
                ),
                'source'    => htmlescape($source),
                'condition' => htmlescape($transition->getCondition()?->label() ?? ''),
                'target'    => htmlescape($target),
            ];
        }

        TemplateRenderer::getInstance()->display('@flowtask/version_children.html.twig', [
            'readonly'   => !$version->isDraft(),
            'add_url'    => $version->canUpdateItem()
                ? self::getFormURL() . '?' . http_build_query([self::$items_id => $version->getID()])
                : null,
            'add_label'  => __('Add a transition', 'flowtask'),
            'columns'    => [
                'link'      => self::getTypeName(1),
                'source'    => __('From step', 'flowtask'),
                'condition' => __('Condition', 'flowtask'),
                'target'    => __('To step', 'flowtask'),
            ],
            'formatters' => ['link' => 'raw_html', 'source' => 'raw_html', 'condition' => 'raw_html', 'target' => 'raw_html'],
            'entries'    => $entries,
        ]);
    }


    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $version  = $this->getItem();
        $sources  = [];
        $targets  = [];
        foreach (Node::getForVersion(Fields::int($this->fields, self::$items_id)) as $node) {
            $type = $node->getNodeType();
            $name = $node->getName();
            if ($type !== NodeType::END) {
                $sources[$node->getID()] = $name;
            }

            if ($type !== NodeType::START) {
                $targets[$node->getID()] = $name;
            }
        }

        $conditions = [];
        foreach (TransitionCondition::cases() as $condition) {
            $conditions[$condition->value] = $condition->label();
        }

        TemplateRenderer::getInstance()->display('@flowtask/transition.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'version_link' => $version instanceof WorkflowVersion ? $version->getLink() : '',
            'sources'      => $sources,
            'targets'      => $targets,
            'conditions'   => $conditions,
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if (!is_array($input) || !$this->isValid($input)) {
            return false;
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // A transition stays in its version, like its steps.
        if (is_array($input)) {
            unset($input[self::$items_id]);
        }

        $input = parent::prepareInputForUpdate($input);
        if (!is_array($input) || !$this->isValid(array_merge($this->fields, $input))) {
            return false;
        }

        return $input;
    }

    /**
     * Checks the transition against the definition management rules of spec
     * 001 and reports every problem found.
     *
     * @param array<string, mixed> $fields Stored fields merged with the input
     */
    private function isValid(array $fields): bool
    {
        $errors = $this->getErrors($fields);
        foreach ($errors as $error) {
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        }

        return $errors === [];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    private function getErrors(array $fields): array
    {
        $versions_id = Fields::int($fields, self::$items_id);
        if (!WorkflowVersion::isDraftId($versions_id)) {
            return [__('Steps and transitions can only be changed in a draft version.', 'flowtask')];
        }

        $source = new Node();
        $target = new Node();
        if (
            !$source->getFromDB(Fields::int($fields, 'plugin_flowtask_nodes_id_source'))
            || !$target->getFromDB(Fields::int($fields, 'plugin_flowtask_nodes_id_target'))
            || Fields::int($source->fields, self::$items_id) !== $versions_id
            || Fields::int($target->fields, self::$items_id) !== $versions_id
        ) {
            return [__('A transition must join two steps of its version.', 'flowtask')];
        }

        $errors = [];
        if ($source->getID() === $target->getID()) {
            $errors[] = __('A transition cannot loop on a single step.', 'flowtask');
        }

        if ($target->isStart()) {
            $errors[] = __('A transition cannot enter the start step.', 'flowtask');
        }

        $source_type = $source->getNodeType();
        $condition   = TransitionCondition::tryFrom(Fields::string($fields, 'trigger_condition'));
        if ($source_type === NodeType::END) {
            $errors[] = __('A transition cannot leave an end step.', 'flowtask');
        } elseif (!$source_type instanceof NodeType || $condition === null || !in_array($condition, TransitionCondition::forSource($source_type), true)) {
            $errors[] = sprintf(
                __('A step of type "%1$s" cannot fire the condition "%2$s".', 'flowtask'),
                $source_type?->label() ?? '',
                $condition?->label() ?? '',
            );
        } else {
            $duplicates = countElementsInTable(self::getTable(), [
                'plugin_flowtask_nodes_id_source' => $source->getID(),
                'trigger_condition'               => $condition->value,
                'NOT'                             => ['id' => Fields::int($fields, 'id')],
            ]);
            if ($duplicates > 0) {
                $errors[] = sprintf(
                    __('Step "%1$s" already has a "%2$s" transition.', 'flowtask'),
                    $source->getName(),
                    $condition->label(),
                );
            }
        }

        return $errors;
    }
}
