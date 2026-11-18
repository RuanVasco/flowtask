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

use DBmysql;
use GlpiPlugin\Flowtask\Definition\VersionPublisher;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Session;
use TaskTemplate;

/**
 * Builds workflow definitions for DbTestCase tests.
 */
trait WorkflowFixtures
{
    private function createWorkflow(): Workflow
    {
        return $this->createItem(Workflow::class, [
            'name'        => $this->getUniqueString(),
            'entities_id' => $this->getTestRootEntity(true),
        ]);
    }

    /**
     * The draft of the workflow: the one a new workflow starts with, or a new
     * one once the previous draft was published.
     */
    private function createDraft(?Workflow $workflow = null): WorkflowVersion
    {
        $workflow ??= $this->createWorkflow();

        return $workflow->getDraft() ?? $this->createItem(WorkflowVersion::class, [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
        ]);
    }

    private function getStartNode(WorkflowVersion $version): Node
    {
        $start = new Node();
        $this->assertTrue($start->getFromDBByCrit([
            'plugin_flowtask_workflowversions_id' => $version->getID(),
            'node_type'                           => NodeType::START->value,
        ]));

        return $start;
    }

    /**
     * Creates a step with a complete configuration for its type.
     *
     * @param array<string, mixed> $extra
     */
    private function createNode(WorkflowVersion $version, NodeType $type, array $extra = []): Node
    {
        $input = [
            'plugin_flowtask_workflowversions_id' => $version->getID(),
            'name'                                => $this->getUniqueString(),
            'node_type'                           => $type->value,
        ];
        // A user actor unless the caller picks another one.
        if (($type === NodeType::TASK || $type === NodeType::APPROVAL) && !isset($extra['actor_type'])) {
            $input['actor_type'] = ActorType::USER->value;
            $input['users_id']   = Session::getLoginUserID();
        }

        if ($type === NodeType::TASK) {
            $input['tasktemplates_id'] = $this->createItem(TaskTemplate::class, [
                'name'    => $this->getUniqueString(),
                'content' => 'Task content',
            ])->getID();
        }

        return $this->createItem(Node::class, $extra + $input);
    }

    private function createTransition(Node $source, Node $target, TransitionCondition $condition): Transition
    {
        return $this->createItem(Transition::class, [
            'plugin_flowtask_workflowversions_id' => $source->fields['plugin_flowtask_workflowversions_id'],
            'plugin_flowtask_nodes_id_source'     => $source->getID(),
            'plugin_flowtask_nodes_id_target'     => $target->getID(),
            'trigger_condition'                   => $condition->value,
        ]);
    }

    /**
     * A draft with START, one TASK step and END, all connected.
     */
    private function createPublishableDraft(?Workflow $workflow = null): WorkflowVersion
    {
        $draft = $this->createDraft($workflow);
        $task  = $this->createNode($draft, NodeType::TASK);
        $end   = $this->createNode($draft, NodeType::END);
        $this->createTransition($this->getStartNode($draft), $task, TransitionCondition::ALWAYS);
        $this->createTransition($task, $end, TransitionCondition::COMPLETED);

        return $draft;
    }

    /**
     * @return list<string>
     */
    private function publish(WorkflowVersion $version): array
    {
        /** @var DBmysql $DB */
        global $DB;

        return (new VersionPublisher($DB))->publish($this->reloadItem($version), (int) Session::getLoginUserID());
    }

    /**
     * Session messages are stored HTML escaped.
     *
     * @param list<string> $messages
     */
    private function assertErrorMessages(array $messages): void
    {
        $this->hasSessionMessages(ERROR, array_map(htmlescape(...), $messages));
    }
}
