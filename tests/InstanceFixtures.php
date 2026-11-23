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
use GlpiPlugin\Flowtask\Engine\WorkflowEngine;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;
use GlpiPlugin\Flowtask\WorkflowVersion;
use ITILCategory;
use Session;
use Ticket;
use TicketTask;
use TicketValidation;
use User;

/**
 * Publishes workflows linked to a category and opens tickets that run them,
 * for DbTestCase tests.
 */
trait InstanceFixtures
{
    use WorkflowFixtures;

    /**
     * START, TASK, END.
     *
     * @return array{ITILCategory, WorkflowVersion, Workflow}
     */
    private function publishLinear(bool $solve = false, int $users_id = 0): array
    {
        return $this->publishGraph(function (WorkflowVersion $draft, Node $start) use ($solve, $users_id): void {
            $task = $this->createNode($draft, NodeType::TASK, $users_id > 0 ? ['users_id' => $users_id] : []);
            $end  = $this->createNode($draft, NodeType::END, ['solve_ticket' => $solve ? 1 : 0]);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        });
    }

    /**
     * @param callable(WorkflowVersion, Node): void $build
     *
     * @return array{ITILCategory, WorkflowVersion, Workflow}
     */
    private function publishGraph(callable $build): array
    {
        $workflow = $this->createActiveWorkflow();
        $draft    = $this->createDraft($workflow);
        $build($draft, $this->getStartNode($draft));
        $this->assertSame([], $this->publish($draft));

        return [$this->linkCategory($workflow), $this->reloadItem($draft), $workflow];
    }

    private function createActiveWorkflow(): Workflow
    {
        return $this->createItem(Workflow::class, [
            'name'         => $this->getUniqueString(),
            'entities_id'  => $this->getTestRootEntity(true),
            'is_recursive' => 1,
            'is_active'    => 1,
        ]);
    }

    private function linkCategory(Workflow $workflow): ITILCategory
    {
        $category = $this->createItem(ITILCategory::class, ['name' => $this->getUniqueString()]);
        $this->createItem(Workflow_ITILCategory::class, [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
            'itilcategories_id'            => $category->getID(),
        ]);

        return $category;
    }

    private function createTicket(?ITILCategory $category, int $requester = 0): Ticket
    {
        return $this->createItem(Ticket::class, [
            'name'              => $this->getUniqueString(),
            'content'           => 'Content',
            'entities_id'       => $this->getTestRootEntity(true),
            'itilcategories_id' => $category?->getID() ?? 0,
            '_actors'           => [
                'requester' => [['itemtype' => User::class, 'items_id' => $requester > 0 ? $requester : Session::getLoginUserID()]],
            ],
        ]);
    }

    private function getInstance(Ticket $ticket): Instance
    {
        $instance = Instance::getForTicket($ticket->getID());
        $this->assertInstanceOf(Instance::class, $instance);

        return $instance;
    }

    private function engine(): WorkflowEngine
    {
        /** @var DBmysql $DB */
        global $DB;

        return WorkflowEngine::fromDb($DB);
    }

    private function getActiveTask(Instance $instance): TicketTask
    {
        $step = InstanceStep::getActiveForInstance($instance->getID());
        $this->assertInstanceOf(InstanceStep::class, $step);
        $this->assertSame(TicketTask::class, $step->fields['itemtype']);

        $task = new TicketTask();
        $this->assertTrue($task->getFromDB((int) $step->fields['items_id']));

        return $task;
    }

    private function getActiveValidation(Instance $instance): TicketValidation
    {
        $step = InstanceStep::getActiveForInstance($instance->getID());
        $this->assertInstanceOf(InstanceStep::class, $step);
        $this->assertSame(TicketValidation::class, $step->fields['itemtype']);

        $validation = new TicketValidation();
        $this->assertTrue($validation->getFromDB((int) $step->fields['items_id']));

        return $validation;
    }

    /**
     * @return list<string> Step statuses, oldest first
     */
    private function getStepStatuses(Instance $instance): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $statuses = [];
        foreach ($DB->request([
            'SELECT' => 'status',
            'FROM'   => InstanceStep::getTable(),
            'WHERE'  => [InstanceStep::$items_id => $instance->getID()],
            'ORDER'  => 'id',
        ]) as $row) {
            $statuses[] = (string) $row['status'];
        }

        return $statuses;
    }

    private function assertStuckMessage(string $reason): void
    {
        $this->hasSessionMessages(WARNING, [htmlescape('The workflow of this ticket is stuck: ' . $reason)]);
    }
}
