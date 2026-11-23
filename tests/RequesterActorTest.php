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

use CommonITILActor;
use CommonITILValidation;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Definition\StepConfiguration;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\WorkflowVersion;
use ITILCategory;
use ITILFollowup;
use Planning;
use TaskTemplate;
use Ticket;
use Ticket_User;
use TicketTask;
use User;

/**
 * Requester as the actor of a step, spec 003 stage 1 (AC-1 to AC-4).
 */
final class RequesterActorTest extends DbTestCase
{
    use InstanceFixtures;

    private const REQUESTER = ['actor_type' => 'requester', 'users_id' => 0, 'groups_id' => 0];

    public function testRequesterStepIsCompleteWithoutOtherActorField(): void
    {
        $this->login();

        // AC-1
        foreach ([NodeType::TASK, NodeType::APPROVAL] as $type) {
            $this->assertSame([], StepConfiguration::getErrors([
                'node_type'        => $type->value,
                'actor_type'       => ActorType::REQUESTER->value,
                'tasktemplates_id' => 1,
            ]));
        }

        // publishGraph() checks that the version publishes without problems.
        [, $version] = $this->publishRequesterTask();
        $this->assertSame('Requester', $this->findNode($version, NodeType::TASK)->getActorLabel());
    }

    public function testRequesterTaskIsAssignedToTheRequesterAndPublic(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category, $version] = $this->publishRequesterTask();

        // AC-2: the template is private, the task is not.
        $template = $this->findNode($version, NodeType::TASK)->fields['tasktemplates_id'];
        $this->updateItem(TaskTemplate::class, (int) $template, ['is_private' => 1]);

        $instance = $this->getInstance($this->createTicket($category, $requester->getID()));
        $task     = $this->getActiveTask($instance);

        $this->assertSame($requester->getID(), (int) $task->fields['users_id_tech']);
        $this->assertSame(0, (int) $task->fields['groups_id_tech']);
        $this->assertSame(0, (int) $task->fields['is_private']);
    }

    public function testRequesterApprovalTargetsTheRequester(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $approval = $this->createNode($draft, NodeType::APPROVAL, self::REQUESTER);
            $end      = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $approval, TransitionCondition::ALWAYS);
            $this->createTransition($approval, $end, TransitionCondition::APPROVED);
            $this->createTransition($approval, $end, TransitionCondition::REJECTED);
        });

        // AC-2
        $validation = $this->getActiveValidation($this->getInstance($this->createTicket($category, $requester->getID())));
        $this->assertSame(User::class, $validation->fields['itemtype_target']);
        $this->assertSame($requester->getID(), (int) $validation->fields['items_id_target']);
    }

    public function testTicketWithoutRequesterUserFlagsStuckAndRetryResumes(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishRequesterTask();

        // A ticket whose only requester user is removed before it gets the category.
        $ticket = $this->createTicket(null, $requester->getID());
        $this->assertTrue((new Ticket_User())->deleteByCriteria([
            'tickets_id' => $ticket->getID(),
            'type'       => CommonITILActor::REQUESTER,
        ]));
        $this->updateItem(Ticket::class, $ticket->getID(), ['itilcategories_id' => $category->getID()]);

        // AC-3
        $instance = $this->getInstance($ticket);
        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertSame('The ticket has no requester user.', $instance->fields['stuck_reason']);
        $this->assertSame(0, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $ticket->getID()]));
        $this->assertStuckMessage('The ticket has no requester user.');

        $this->createItem(Ticket_User::class, [
            'tickets_id' => $ticket->getID(),
            'users_id'   => $requester->getID(),
            'type'       => CommonITILActor::REQUESTER,
        ]);
        $this->assertTrue($this->engine()->retry($instance));

        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::RUNNING, $instance->getStatus());
        $this->assertSame($requester->getID(), (int) $this->getActiveTask($instance)->fields['users_id_tech']);
    }

    public function testPublicFollowupOfTheRequesterCompletesTheTask(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishRequesterTask();
        $ticket     = $this->createTicket($category, $requester->getID());
        $instance   = $this->getInstance($ticket);
        $task       = $this->getActiveTask($instance);

        // AC-4: anyone else, or a private followup, changes nothing.
        $this->addFollowup($ticket, $this->createUser()->getID(), false);
        $this->addFollowup($ticket, $requester->getID(), true);
        $this->assertSame(Planning::TODO, (int) $this->reloadItem($task)->fields['state']);
        $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($instance)->getStatus());

        $this->addFollowup($ticket, $requester->getID(), false);

        $this->assertSame(Planning::DONE, (int) $this->reloadItem($task)->fields['state']);
        $instance = $this->reloadItem($instance);
        $this->assertSame(InstanceStatus::COMPLETED, $instance->getStatus());
        $this->assertSame([StepStatus::DONE->value, StepStatus::DONE->value], $this->getStepStatuses($instance));

        // A later followup changes nothing.
        $this->addFollowup($ticket, $requester->getID(), false);
        $this->assertSame(InstanceStatus::COMPLETED, $this->reloadItem($instance)->getStatus());
    }

    public function testFollowupOfTheRequesterOnAnotherActorTaskChangesNothing(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishLinear(users_id: $requester->getID());
        $ticket     = $this->createTicket($category, $requester->getID());
        $instance   = $this->getInstance($ticket);

        // AC-4: the task is assigned to the requester, but through a user actor.
        $this->addFollowup($ticket, $requester->getID(), false);

        $this->assertSame(Planning::TODO, (int) $this->getActiveTask($instance)->fields['state']);
        $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($instance)->getStatus());
    }

    public function testFollowupOfTheRequesterOnItsApprovalChangesNothing(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $approval = $this->createNode($draft, NodeType::APPROVAL, self::REQUESTER);
            $end      = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $approval, TransitionCondition::ALWAYS);
            $this->createTransition($approval, $end, TransitionCondition::APPROVED);
            $this->createTransition($approval, $end, TransitionCondition::REJECTED);
        });
        $ticket   = $this->createTicket($category, $requester->getID());
        $instance = $this->getInstance($ticket);

        // AC-4: only a task step is answered by followup.
        $this->addFollowup($ticket, $requester->getID(), false);

        $this->assertSame(CommonITILValidation::WAITING, (int) $this->getActiveValidation($instance)->fields['status']);
        $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($instance)->getStatus());
    }

    public function testInactiveRequesterFlagsInstanceStuck(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishRequesterTask();

        // GLPI does not add an inactive user as an actor: deactivate it afterwards.
        $ticket = $this->createTicket(null, $requester->getID());
        $this->updateItem(User::class, $requester->getID(), ['is_active' => 0]);
        $this->updateItem(Ticket::class, $ticket->getID(), ['itilcategories_id' => $category->getID()]);

        $instance = $this->getInstance($ticket);

        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertStuckMessage('The requester of the ticket is deleted or inactive.');
    }

    /**
     * START, TASK for the requester, END.
     *
     * @return array{ITILCategory, WorkflowVersion}
     */
    private function publishRequesterTask(): array
    {
        [$category, $version] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $task = $this->createNode($draft, NodeType::TASK, self::REQUESTER);
            $end  = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        });

        return [$category, $version];
    }

    private function findNode(WorkflowVersion $version, NodeType $type): Node
    {
        $node = new Node();
        $this->assertTrue($node->getFromDBByCrit([Node::$items_id => $version->getID(), 'node_type' => $type->value]));

        return $node;
    }

    private function createUser(): User
    {
        return $this->createItem(User::class, ['name' => $this->getUniqueString()]);
    }

    private function addFollowup(Ticket $ticket, int $users_id, bool $private): void
    {
        $this->createItem(ITILFollowup::class, [
            'itemtype'   => Ticket::class,
            'items_id'   => $ticket->getID(),
            'users_id'   => $users_id,
            'is_private' => $private ? 1 : 0,
            'content'    => 'Answer',
        ]);
    }
}
