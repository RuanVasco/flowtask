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

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;
use GlpiPlugin\Flowtask\WorkflowVersion;
use ITILCategory;
use Ticket;
use User;

final class PurgeTest extends DbTestCase
{
    public function testPurgingWorkflowRemovesItsDefinition(): void
    {
        $this->login();

        $workflow = $this->createItem(Workflow::class, [
            'name'        => __FUNCTION__,
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $version = $this->createItem(WorkflowVersion::class, [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
        ]);
        $start = $this->createNode($version, NodeType::START);
        $end   = $this->createNode($version, NodeType::END);
        $this->createItem(Transition::class, [
            'plugin_flowtask_workflowversions_id' => $version->getID(),
            'plugin_flowtask_nodes_id_source'     => $start->getID(),
            'plugin_flowtask_nodes_id_target'     => $end->getID(),
            'trigger_condition'                   => TransitionCondition::ALWAYS->value,
        ]);
        $category = $this->createItem(ITILCategory::class, ['name' => __FUNCTION__]);
        $this->createItem(Workflow_ITILCategory::class, [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
            'itilcategories_id'            => $category->getID(),
        ]);

        $this->assertTrue($workflow->delete(['id' => $workflow->getID()], true));

        $this->assertSame(0, countElementsInTable(WorkflowVersion::getTable(), ['plugin_flowtask_workflows_id' => $workflow->getID()]));
        $this->assertSame(0, countElementsInTable(Node::getTable(), ['plugin_flowtask_workflowversions_id' => $version->getID()]));
        $this->assertSame(0, countElementsInTable(Transition::getTable(), ['plugin_flowtask_workflowversions_id' => $version->getID()]));
        $this->assertSame(0, countElementsInTable(Workflow_ITILCategory::getTable(), ['plugin_flowtask_workflows_id' => $workflow->getID()]));
    }

    public function testPurgingTicketRemovesItsInstance(): void
    {
        $this->login();

        $node = $this->createNode($this->createVersion(), NodeType::TASK);

        // Two tickets, so a reset-to-0 strategy would hit the unique key on the second purge.
        foreach ([1, 2] as $i) {
            $ticket = $this->createItem(Ticket::class, [
                'name'        => __FUNCTION__ . $i,
                'content'     => __FUNCTION__,
                'entities_id' => $this->getTestRootEntity(true),
            ]);
            $instance = $this->createItem(Instance::class, [
                'tickets_id'                          => $ticket->getID(),
                'plugin_flowtask_workflowversions_id' => $node->fields['plugin_flowtask_workflowversions_id'],
            ]);
            $this->createItem(InstanceStep::class, [
                'plugin_flowtask_instances_id' => $instance->getID(),
                'plugin_flowtask_nodes_id'     => $node->getID(),
            ]);

            $this->assertTrue($ticket->delete(['id' => $ticket->getID()], true));

            $this->assertSame(0, countElementsInTable(Instance::getTable(), ['id' => $instance->getID()]));
            $this->assertSame(0, countElementsInTable(InstanceStep::getTable(), ['plugin_flowtask_instances_id' => $instance->getID()]));
        }
    }

    public function testPurgingCategoryRemovesItsLink(): void
    {
        $this->login();

        $workflow = $this->createItem(Workflow::class, [
            'name'        => __FUNCTION__,
            'entities_id' => $this->getTestRootEntity(true),
        ]);

        foreach ([1, 2] as $i) {
            $category = $this->createItem(ITILCategory::class, ['name' => __FUNCTION__ . $i]);
            $link = $this->createItem(Workflow_ITILCategory::class, [
                'plugin_flowtask_workflows_id' => $workflow->getID(),
                'itilcategories_id'            => $category->getID(),
            ]);

            $this->assertTrue($category->delete(['id' => $category->getID()], true));
            $this->assertSame(0, countElementsInTable(Workflow_ITILCategory::getTable(), ['id' => $link->getID()]));
        }
    }

    public function testPurgingActorUserResetsNodeActor(): void
    {
        $this->login();

        $user = $this->createItem(User::class, ['name' => __FUNCTION__]);
        $node = $this->createNode($this->createVersion(), NodeType::TASK, ['users_id' => $user->getID()]);

        $this->assertTrue($user->delete(['id' => $user->getID()], true));

        $node = $this->reloadItem($node);
        $this->assertSame(0, (int) $node->fields['users_id']);
    }

    private function createVersion(): WorkflowVersion
    {
        $workflow = $this->createItem(Workflow::class, [
            'name'        => $this->getUniqueString(),
            'entities_id' => $this->getTestRootEntity(true),
        ]);

        return $this->createItem(WorkflowVersion::class, [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createNode(WorkflowVersion $version, NodeType $type, array $extra = []): Node
    {
        return $this->createItem(Node::class, [
            'plugin_flowtask_workflowversions_id' => $version->getID(),
            'name'                                => $type->name,
            'node_type'                           => $type->value,
        ] + $extra);
    }
}
