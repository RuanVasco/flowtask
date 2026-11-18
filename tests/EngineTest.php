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

use CommonITILValidation;
use CronTask;
use DBmysql;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Engine\WorkflowEngine;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Enum\MissingManagerBehavior;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Group;
use ITILCategory;
use ITILSolution;
use Planning;
use Session;
use Ticket;
use TicketTask;
use TicketValidation;
use User;

/**
 * Workflow engine, spec 001 stage 3 (AC-3 to AC-8, AC-12 to AC-14).
 */
final class EngineTest extends DbTestCase
{
    use WorkflowFixtures;

    public function testTicketInLinkedCategoryStartsOnPublishedVersionAndCreatesTask(): void
    {
        $this->login();
        [$category, $version] = $this->publishLinear();

        $ticket   = $this->createTicket($category);
        $instance = $this->getInstance($ticket);

        $this->assertSame(InstanceStatus::RUNNING, $instance->getStatus());
        $this->assertSame($version->getID(), (int) $instance->fields['plugin_flowtask_workflowversions_id']);

        $task = $this->getActiveTask($instance);
        $this->assertSame(Session::getLoginUserID(), (int) $task->fields['users_id_tech']);
        $this->assertSame(0, (int) $task->fields['groups_id_tech']);
        $this->assertSame(Planning::TODO, (int) $task->fields['state']);
    }

    public function testNothingStartsWithoutAnActivePublishedWorkflow(): void
    {
        $this->login();

        // No category.
        $this->assertNull(Instance::getForTicket($this->createTicket(null)->getID()));

        // Inactive workflow.
        [$category, , $workflow] = $this->publishLinear();
        $this->updateItem(Workflow::class, $workflow->getID(), ['is_active' => 0]);
        $this->assertNull(Instance::getForTicket($this->createTicket($category)->getID()));

        // Linked workflow that was never published.
        $draft_only = $this->createActiveWorkflow();
        $other      = $this->linkCategory($draft_only);
        $this->assertNull(Instance::getForTicket($this->createTicket($other)->getID()));
    }

    public function testChangingToLinkedCategoryStartsOnceOnly(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        [$second]   = $this->publishLinear();

        $ticket = $this->createTicket(null);
        $this->assertNull(Instance::getForTicket($ticket->getID()));

        $this->updateItem(Ticket::class, $ticket->getID(), ['itilcategories_id' => $category->getID()]);
        $instance = $this->getInstance($ticket);

        $this->updateItem(Ticket::class, $ticket->getID(), ['itilcategories_id' => $second->getID()]);
        $this->assertSame(1, countElementsInTable(Instance::getTable(), ['tickets_id' => $ticket->getID()]));
        $this->assertSame($instance->getID(), $this->getInstance($ticket)->getID());
    }

    public function testDoneTaskAdvancesToEndAndSolvesTicket(): void
    {
        $this->login();
        [$category] = $this->publishLinear(solve: true);
        $ticket     = $this->createTicket($category);
        $instance   = $this->getInstance($ticket);
        $task       = $this->getActiveTask($instance);

        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::DONE]);

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::COMPLETED, $instance->getStatus());
        $this->assertNotEmpty($instance->fields['date_end']);
        $this->assertSame(1, countElementsInTable(ITILSolution::getTable(), [
            'itemtype' => Ticket::class,
            'items_id' => $ticket->getID(),
        ]));
        $this->assertSame(
            [StepStatus::DONE->value, StepStatus::DONE->value],
            $this->getStepStatuses($instance),
        );
    }

    public function testReopeningADoneTaskDoesNotMoveTheInstanceAgain(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $instance   = $this->getInstance($this->createTicket($category));
        $task       = $this->getActiveTask($instance);

        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::DONE]);
        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::TODO]);
        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::DONE]);

        $this->assertCount(2, $this->getStepStatuses($instance));
    }

    public function testApprovalFollowsApprovedOrRejected(): void
    {
        $this->login();
        [$category] = $this->publishApproval();

        // Accepted: straight to END.
        $accepted   = $this->getInstance($this->createTicket($category));
        $validation = $this->getActiveValidation($accepted);
        $this->assertSame(User::class, $validation->fields['itemtype_target']);
        $this->updateItem(TicketValidation::class, $validation->getID(), ['status' => CommonITILValidation::ACCEPTED]);
        $this->assertSame(InstanceStatus::COMPLETED, $this->reloadItem($accepted)->getStatus());

        // Refused: a rework task.
        $refused    = $this->getInstance($this->createTicket($category));
        $validation = $this->getActiveValidation($refused);
        $this->updateItem(TicketValidation::class, $validation->getID(), [
            'status'             => CommonITILValidation::REFUSED,
            'comment_validation' => 'No',
        ]);
        $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($refused)->getStatus());
        $this->getActiveTask($refused);
    }

    public function testGroupActorIsAssignedToTaskAndValidation(): void
    {
        $this->login();
        $group = $this->createItem(Group::class, ['name' => $this->getUniqueString()]);
        $actor = ['actor_type' => ActorType::GROUP->value, 'users_id' => 0, 'groups_id' => $group->getID()];
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start) use ($actor): void {
            $task     = $this->createNode($draft, NodeType::TASK, $actor);
            $approval = $this->createNode($draft, NodeType::APPROVAL, $actor);
            $end      = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $approval, TransitionCondition::COMPLETED);
            $this->createTransition($approval, $end, TransitionCondition::APPROVED);
            $this->createTransition($approval, $end, TransitionCondition::REJECTED);
        });
        $instance = $this->getInstance($this->createTicket($category));

        $task = $this->getActiveTask($instance);
        $this->assertSame(0, (int) $task->fields['users_id_tech']);
        $this->assertSame($group->getID(), (int) $task->fields['groups_id_tech']);
        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::DONE]);

        $validation = $this->getActiveValidation($instance);
        $this->assertSame(Group::class, $validation->fields['itemtype_target']);
        $this->assertSame($group->getID(), (int) $validation->fields['items_id_target']);
    }

    public function testRequesterManagerIsResolvedNLevelsUp(): void
    {
        $this->login();
        $director  = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
        $manager   = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'users_id_supervisor' => $director->getID()]);
        $requester = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'users_id_supervisor' => $manager->getID()]);
        [$category] = $this->publishManagerTask(2, MissingManagerBehavior::BLOCK);

        $task = $this->getActiveTask($this->getInstance($this->createTicket($category, $requester->getID())));

        $this->assertSame($director->getID(), (int) $task->fields['users_id_tech']);
    }

    public function testMissingManagerBlocksThenRetrySucceeds(): void
    {
        $this->login();
        $requester  = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
        [$category] = $this->publishManagerTask(1, MissingManagerBehavior::BLOCK);

        $instance = $this->getInstance($this->createTicket($category, $requester->getID()));
        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertNotEmpty($instance->fields['stuck_reason']);
        $this->assertSame([StepStatus::ACTIVE->value], $this->getStepStatuses($instance));
        $this->assertStuckMessage('The requester has no active manager at level 1.');

        $manager = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
        $this->updateItem(User::class, $requester->getID(), ['users_id_supervisor' => $manager->getID()]);
        $this->assertTrue($this->engine()->retry($instance));

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::RUNNING, $instance->getStatus());
        $this->assertSame([StepStatus::ACTIVE->value], $this->getStepStatuses($instance));
        $this->assertSame($manager->getID(), (int) $this->getActiveTask($instance)->fields['users_id_tech']);
    }

    public function testMissingManagerSkipsAlongTheSuccessPath(): void
    {
        $this->login();
        $requester  = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
        [$category] = $this->publishManagerTask(1, MissingManagerBehavior::SKIP);

        $instance = $this->getInstance($this->createTicket($category, $requester->getID()));

        $this->assertSame(InstanceStatus::COMPLETED, $instance->getStatus());
        $this->assertSame([StepStatus::SKIPPED->value, StepStatus::DONE->value], $this->getStepStatuses($instance));
        $this->assertSame(0, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $instance->fields['tickets_id']]));
    }

    public function testInactiveUserActorFlagsInstanceStuck(): void
    {
        $this->login();
        $user       = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'is_active' => 0]);
        [$category] = $this->publishLinear(users_id: $user->getID());

        $instance = $this->getInstance($this->createTicket($category));

        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertSame(0, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $instance->fields['tickets_id']]));
        $this->assertStuckMessage('The user of the step is missing, deleted or inactive.');
    }

    public function testLoopOfSkippedStepsFlagsInstanceStuck(): void
    {
        $this->login();
        $requester = $this->createItem(User::class, ['name' => $this->getUniqueString()]);
        $skip      = [
            'actor_type'               => ActorType::REQUESTER_MANAGER->value,
            'missing_manager_behavior' => MissingManagerBehavior::SKIP->value,
        ];
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start) use ($skip): void {
            $task     = $this->createNode($draft, NodeType::TASK, $skip);
            $approval = $this->createNode($draft, NodeType::APPROVAL, $skip);
            $end      = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $approval, TransitionCondition::COMPLETED);
            $this->createTransition($approval, $task, TransitionCondition::APPROVED);
            $this->createTransition($approval, $end, TransitionCondition::REJECTED);
        });

        $instance = $this->getInstance($this->createTicket($category, $requester->getID()));

        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertCount(WorkflowEngine::MAX_STEPS_PER_RUN + 1, $this->getStepStatuses($instance));
        $this->assertStuckMessage(sprintf(
            'More than %d steps were entered in a row without waiting for anyone.',
            WorkflowEngine::MAX_STEPS_PER_RUN,
        ));
    }

    public function testRunningInstanceKeepsItsVersionAfterANewOneIsPublished(): void
    {
        $this->login();
        [$category, $version, $workflow] = $this->publishLinear();
        $instance = $this->getInstance($this->createTicket($category));

        // A new version: a copy of the graph with new step ids.
        $draft = $this->createDraft($workflow);
        $this->assertSame([], $this->publish($draft));

        $this->updateItem(TicketTask::class, $this->getActiveTask($instance)->getID(), ['state' => Planning::DONE]);

        $instance = $this->reloadItem($instance);
        $this->assertSame($version->getID(), (int) $instance->fields['plugin_flowtask_workflowversions_id']);
        $this->assertSame(InstanceStatus::COMPLETED, $instance->getStatus());
        $this->assertSame(
            $version->getID(),
            (int) $this->getLastStepNode($instance)->fields['plugin_flowtask_workflowversions_id'],
        );
    }

    public function testItemsOfActiveInstanceCannotBePurged(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $instance   = $this->getInstance($this->createTicket($category));
        $task       = $this->getActiveTask($instance);

        $this->assertFalse($task->delete(['id' => $task->getID()], true));
        $this->assertTrue((new TicketTask())->getFromDB($task->getID()));
        $this->hasSessionMessages(ERROR, [htmlescape('This item was created by a running workflow and cannot be deleted.')]);

        // Once the instance has ended, the task is an ordinary task again.
        $this->assertTrue($this->engine()->cancel($instance));
        $this->assertTrue($task->delete(['id' => $task->getID()], true));
    }

    public function testPurgingTheTicketStillPurgesItsWorkflowItems(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $ticket     = $this->createTicket($category);
        $instance   = $this->getInstance($ticket);
        $task       = $this->getActiveTask($instance);

        $this->assertTrue($ticket->delete(['id' => $ticket->getID()], true));

        $this->assertFalse((new TicketTask())->getFromDB($task->getID()));
        $this->assertNull(Instance::getForTicket($ticket->getID()));
    }

    public function testLostItemIsDetectedByTheAutomaticAction(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->login();
        [$category] = $this->publishLinear();
        $instance   = $this->getInstance($this->createTicket($category));
        $task       = $this->getActiveTask($instance);
        $DB->delete(TicketTask::getTable(), ['id' => $task->getID()]);

        $cron = new CronTask();
        $this->assertSame(1, Instance::cronCheckInstances($cron));

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertSame(0, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $instance->fields['tickets_id']]));
        $this->assertStuckMessage(sprintf(
            'The task or approval of the step "%s" was removed.',
            $this->getLastStepNode($instance)->getName(),
        ));
    }

    public function testLostItemIsDetectedWhenTheTicketIsUpdated(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->login();
        [$category] = $this->publishLinear();
        $ticket     = $this->createTicket($category);
        $instance   = $this->getInstance($ticket);
        $DB->delete(TicketTask::getTable(), ['id' => $this->getActiveTask($instance)->getID()]);

        $this->updateItem(Ticket::class, $ticket->getID(), ['urgency' => 5]);

        $this->assertSame(InstanceStatus::STUCK, $this->reloadItem($instance)->getStatus());
        $this->assertStuckMessage(sprintf(
            'The task or approval of the step "%s" was removed.',
            $this->getLastStepNode($instance)->getName(),
        ));
    }

    public function testMissingTransitionKeepsTheStepSoRetryCanFollowItsAnswer(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->login();
        [$category] = $this->publishLinear();
        $instance   = $this->getInstance($this->createTicket($category));
        $task       = $this->getActiveTask($instance);
        $node       = $this->getLastStepNode($instance);

        // Only a direct database change can remove a transition of a published version.
        $transition = $DB->request([
            'FROM'  => 'glpi_plugin_flowtask_transitions',
            'WHERE' => ['plugin_flowtask_nodes_id_source' => $node->getID()],
        ])->current();
        $this->assertIsArray($transition);
        $DB->delete('glpi_plugin_flowtask_transitions', ['id' => $transition['id']]);

        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::DONE]);

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertSame([StepStatus::ACTIVE->value], $this->getStepStatuses($instance));
        $this->assertStuckMessage(sprintf('The step "%s" has no "Completed" transition.', $node->getName()));

        $DB->insert('glpi_plugin_flowtask_transitions', $transition);
        $this->assertTrue($this->engine()->retry($instance));

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::COMPLETED, $instance->getStatus());
        $this->assertSame([StepStatus::DONE->value, StepStatus::DONE->value], $this->getStepStatuses($instance));
        $this->assertSame(1, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $instance->fields['tickets_id']]));
    }

    public function testCancelClosesTheActiveStep(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $instance   = $this->getInstance($this->createTicket($category));

        $this->assertTrue($this->engine()->cancel($instance));

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::CANCELLED, $instance->getStatus());
        $this->assertSame([StepStatus::CANCELLED->value], $this->getStepStatuses($instance));
        $this->assertFalse($this->engine()->cancel($instance));
    }

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
     * START, APPROVAL; approved goes to END, rejected to a rework TASK then END.
     *
     * @return array{ITILCategory, WorkflowVersion, Workflow}
     */
    private function publishApproval(): array
    {
        return $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $approval = $this->createNode($draft, NodeType::APPROVAL);
            $rework   = $this->createNode($draft, NodeType::TASK);
            $end      = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $approval, TransitionCondition::ALWAYS);
            $this->createTransition($approval, $end, TransitionCondition::APPROVED);
            $this->createTransition($approval, $rework, TransitionCondition::REJECTED);
            $this->createTransition($rework, $end, TransitionCondition::COMPLETED);
        });
    }

    /**
     * START, TASK for the requester manager, END.
     *
     * @return array{ITILCategory, WorkflowVersion, Workflow}
     */
    private function publishManagerTask(int $level, MissingManagerBehavior $behavior): array
    {
        return $this->publishGraph(function (WorkflowVersion $draft, Node $start) use ($level, $behavior): void {
            $task = $this->createNode($draft, NodeType::TASK, [
                'actor_type'               => ActorType::REQUESTER_MANAGER->value,
                'manager_level'            => $level,
                'missing_manager_behavior' => $behavior->value,
            ]);
            $end = $this->createNode($draft, NodeType::END);
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

    private function getLastStepNode(Instance $instance): Node
    {
        $step = new InstanceStep();
        $this->assertTrue($step->getFromDBByRequest([
            'WHERE' => [InstanceStep::$items_id => $instance->getID()],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]));
        $node = $step->getNode();
        $this->assertInstanceOf(Node::class, $node);

        return $node;
    }

    private function assertStuckMessage(string $reason): void
    {
        $this->hasSessionMessages(WARNING, [htmlescape('The workflow of this ticket is stuck: ' . $reason)]);
    }

    private function engine(): WorkflowEngine
    {
        /** @var DBmysql $DB */
        global $DB;

        return WorkflowEngine::fromDb($DB);
    }
}
