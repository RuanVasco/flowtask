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
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Definition\StepConfiguration;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Enum\MissingManagerBehavior;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use ITILCategory;
use ITILFollowup;
use Planning;
use Ticket;
use TicketTask;
use TicketValidation;
use User;

/**
 * Ticket status per step, spec 003 stage 2 (AC-5 to AC-9).
 */
final class TicketStatusTest extends DbTestCase
{
    use InstanceFixtures;

    private const REQUESTER = ['actor_type' => 'requester', 'users_id' => 0, 'groups_id' => 0];

    public function testOnlyAllowedStatusesAreStoredOnTaskAndApprovalSteps(): void
    {
        $this->login();
        $draft = $this->createDraft();

        // AC-5
        foreach ([Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING] as $status) {
            $this->assertSame($status, $this->statusOf($this->createNode($draft, NodeType::TASK, ['ticket_status' => $status])));
            $this->assertSame($status, $this->statusOf($this->createNode($draft, NodeType::APPROVAL, ['ticket_status' => $status])));
        }

        foreach ([Ticket::INCOMING, Ticket::SOLVED, Ticket::CLOSED, 99, -1] as $status) {
            $this->assertSame(0, $this->statusOf($this->addNode($draft, NodeType::TASK, $status)));
        }

        $this->assertSame(0, $this->statusOf($this->addNode($draft, NodeType::END, Ticket::WAITING)));

        // The start step cannot be edited at all: check the rule itself.
        $this->assertSame(0, StepConfiguration::normalize([
            'node_type'     => NodeType::START->value,
            'ticket_status' => Ticket::WAITING,
        ])['ticket_status']);
    }

    public function testNewDraftKeepsTheStatusOfItsSteps(): void
    {
        $this->login();
        [, $version, $workflow] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $task = $this->createNode($draft, NodeType::TASK, ['ticket_status' => Ticket::PLANNED]);
            $end  = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        });

        $draft = $this->createDraft($workflow);

        $this->assertNotSame($version->getID(), $draft->getID());
        $this->assertSame(1, countElementsInTable(Node::getTable(), [
            Node::$items_id => $draft->getID(),
            'ticket_status' => Ticket::PLANNED,
        ]));
    }

    public function testEnteringAStepSetsItsStatusOnceTheTaskExists(): void
    {
        $this->login();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $task = $this->createNode($draft, NodeType::TASK, ['ticket_status' => Ticket::PLANNED]);
            $end  = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        });

        // AC-7
        $ticket = $this->createTicket($category);
        $this->assertSame(1, countElementsInTable(TicketTask::getTable(), ['tickets_id' => $ticket->getID()]));
        $this->assertSame(Ticket::PLANNED, $this->ticketStatus($ticket));
    }

    public function testSkippedStepAndStuckInstanceLeaveTheStatusAlone(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            // Skipped: the requester has no manager.
            $skipped = $this->createNode($draft, NodeType::TASK, [
                'actor_type'               => ActorType::REQUESTER_MANAGER->value,
                'missing_manager_behavior' => MissingManagerBehavior::SKIP->value,
                'ticket_status'            => Ticket::WAITING,
            ]);
            // Stuck: its user is inactive.
            $stuck = $this->createNode($draft, NodeType::TASK, [
                'users_id'      => $this->createItem(User::class, ['name' => $this->getUniqueString(), 'is_active' => 0])->getID(),
                'ticket_status' => Ticket::PLANNED,
            ]);
            $end = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $skipped, TransitionCondition::ALWAYS);
            $this->createTransition($skipped, $stuck, TransitionCondition::COMPLETED);
            $this->createTransition($stuck, $end, TransitionCondition::COMPLETED);
        });

        // AC-7
        $ticket = $this->createTicket($category, $requester->getID());
        $this->assertSame(InstanceStatus::STUCK, $this->getInstance($ticket)->getStatus());
        $this->assertStuckMessage('The user of the step is missing, deleted or inactive.');
        $this->assertSame(Ticket::INCOMING, $this->ticketStatus($ticket));
    }

    public function testLeavingAPendingStepPutsTheTicketBackToProcessing(): void
    {
        $this->login();
        $requester  = $this->createUser();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $answer = $this->createNode($draft, NodeType::TASK, self::REQUESTER + ['ticket_status' => Ticket::WAITING]);
            $work   = $this->createNode($draft, NodeType::TASK);
            $end    = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $answer, TransitionCondition::ALWAYS);
            $this->createTransition($answer, $work, TransitionCondition::COMPLETED);
            $this->createTransition($work, $end, TransitionCondition::COMPLETED);
        });
        $ticket = $this->createTicket($category, $requester->getID());
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($ticket));

        // AC-8: the requester answers, the next step does not change the status.
        $this->createItem(ITILFollowup::class, [
            'itemtype' => Ticket::class,
            'items_id' => $ticket->getID(),
            'users_id' => $requester->getID(),
            'content'  => 'Answer',
        ]);

        $this->assertSame(Ticket::ASSIGNED, $this->ticketStatus($ticket));
    }

    public function testEndStepThatDoesNotSolveReleasesThePendingTicket(): void
    {
        $this->login();
        [$category] = $this->publishPendingTaskToEnd(solve: false);
        $ticket     = $this->createTicket($category);
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($ticket));

        // AC-8
        $this->completeActiveTask($ticket);

        $this->assertSame(InstanceStatus::COMPLETED, $this->getInstance($ticket)->getStatus());
        $this->assertSame(Ticket::ASSIGNED, $this->ticketStatus($ticket));
    }

    public function testEndStepThatSolvesLeavesTheTicketSolved(): void
    {
        $this->login();
        [$category] = $this->publishPendingTaskToEnd(solve: true);
        $ticket     = $this->createTicket($category);

        $this->completeActiveTask($ticket);

        $this->assertTrue(in_array($this->ticketStatus($ticket), [Ticket::SOLVED, Ticket::CLOSED], true));
    }

    public function testTicketPutOnPendingByHandIsNotTouched(): void
    {
        $this->login();
        // An approval: GLPI itself takes a ticket off pending when a task of
        // its timeline is added or changed.
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $approval = $this->createNode($draft, NodeType::APPROVAL);
            $end      = $this->createNode($draft, NodeType::END, ['solve_ticket' => 0]);
            $this->createTransition($start, $approval, TransitionCondition::ALWAYS);
            $this->createTransition($approval, $end, TransitionCondition::APPROVED);
            $this->createTransition($approval, $end, TransitionCondition::REJECTED);
        });
        $ticket = $this->createTicket($category);
        $this->updateItem(Ticket::class, $ticket->getID(), ['status' => Ticket::WAITING]);

        // AC-8: only a pending step releases the ticket.
        $this->answer($ticket, CommonITILValidation::ACCEPTED);
        $this->assertSame(InstanceStatus::COMPLETED, $this->getInstance($ticket)->getStatus());
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($ticket));
    }

    public function testPendingStepAfterACompletedTaskKeepsTheTicketPending(): void
    {
        $this->login();
        $requester = getItemByTypeName(User::class, 'post-only', true);
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $answer  = $this->createNode($draft, NodeType::TASK, self::REQUESTER + ['ticket_status' => Ticket::WAITING]);
            $confirm = $this->createNode($draft, NodeType::APPROVAL, self::REQUESTER + ['ticket_status' => Ticket::WAITING]);
            $end     = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $answer, TransitionCondition::ALWAYS);
            $this->createTransition($answer, $confirm, TransitionCondition::COMPLETED);
            $this->createTransition($confirm, $end, TransitionCondition::APPROVED);
            $this->createTransition($confirm, $end, TransitionCondition::REJECTED);
        });
        $by_followup = $this->createTicket($category, $requester);
        $by_form     = $this->createTicket($category, $requester);

        // A technician completes the task from its form, pending toggle off:
        // GLPI would reopen the ticket the approval has just put on pending.
        $this->updateItem(TicketTask::class, $this->getActiveTask($this->getInstance($by_form))->getID(), [
            'state'   => Planning::DONE,
            'pending' => 0,
        ], ['pending']);
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($by_form));

        // The requester answers from their own session: GLPI reopens the
        // ticket for the followup, then the approval puts it on pending again.
        $this->login('post-only', 'postonly');
        $this->createItem(ITILFollowup::class, [
            'itemtype' => Ticket::class,
            'items_id' => $by_followup->getID(),
            'content'  => 'Answer',
        ]);
        $this->getActiveValidation($this->getInstance($by_followup));
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($by_followup));
    }

    public function testRetryAfterAStuckStepReleasesThePendingTicket(): void
    {
        $this->login();
        $approver   = $this->createUser();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start) use ($approver): void {
            // Approvals only: GLPI itself takes a ticket off pending when a task is added.
            $hold = $this->createNode($draft, NodeType::APPROVAL, ['ticket_status' => Ticket::WAITING]);
            $next = $this->createNode($draft, NodeType::APPROVAL, ['users_id' => $approver->getID()]);
            $end  = $this->createNode($draft, NodeType::END, ['solve_ticket' => 0]);
            $this->createTransition($start, $hold, TransitionCondition::ALWAYS);
            $this->createTransition($hold, $next, TransitionCondition::APPROVED);
            $this->createTransition($hold, $end, TransitionCondition::REJECTED);
            $this->createTransition($next, $end, TransitionCondition::APPROVED);
            $this->createTransition($next, $end, TransitionCondition::REJECTED);
        });
        $ticket = $this->createTicket($category);
        $this->updateItem(User::class, $approver->getID(), ['is_active' => 0]);

        // A stuck instance leaves the status alone (AC-7)...
        $this->answer($ticket, CommonITILValidation::ACCEPTED);
        $instance = $this->getInstance($ticket);
        $this->assertSame(InstanceStatus::STUCK, $instance->getStatus());
        $this->assertStuckMessage('The user of the step is missing, deleted or inactive.');
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($ticket));

        // ...and the retried step still releases the ticket (AC-8).
        $this->updateItem(User::class, $approver->getID(), ['is_active' => 1]);
        $this->assertTrue($this->engine()->retry($instance));
        $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($instance)->getStatus());
        $this->assertSame(Ticket::ASSIGNED, $this->ticketStatus($ticket));
    }

    public function testCancellingTheInstanceReleasesThePendingTicket(): void
    {
        $this->login();
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $hold = $this->createNode($draft, NodeType::APPROVAL, ['ticket_status' => Ticket::WAITING]);
            $end  = $this->createNode($draft, NodeType::END);
            $this->createTransition($start, $hold, TransitionCondition::ALWAYS);
            $this->createTransition($hold, $end, TransitionCondition::APPROVED);
            $this->createTransition($hold, $end, TransitionCondition::REJECTED);
        });
        $ticket = $this->createTicket($category);
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($ticket));

        $this->assertTrue($this->engine()->cancel($this->getInstance($ticket)));

        $this->assertSame(Ticket::ASSIGNED, $this->ticketStatus($ticket));
    }

    public function testStatusChangesWhenTheRequesterAnswersWithoutStatusRights(): void
    {
        $this->login();
        $requester = getItemByTypeName(User::class, 'post-only', true);
        [$category] = $this->publishGraph(function (WorkflowVersion $draft, Node $start): void {
            $approval = $this->createNode($draft, NodeType::APPROVAL, self::REQUESTER + ['ticket_status' => Ticket::WAITING]);
            $work     = $this->createNode($draft, NodeType::TASK, ['ticket_status' => Ticket::PLANNED]);
            $end      = $this->createNode($draft, NodeType::END, ['solve_ticket' => 0]);
            $this->createTransition($start, $approval, TransitionCondition::ALWAYS);
            $this->createTransition($approval, $work, TransitionCondition::APPROVED);
            $this->createTransition($approval, $end, TransitionCondition::REJECTED);
            $this->createTransition($work, $end, TransitionCondition::COMPLETED);
        });
        $approved = $this->createTicket($category, $requester);
        $rejected = $this->createTicket($category, $requester);
        $this->assertSame(Ticket::WAITING, $this->ticketStatus($approved));

        // AC-9: the Self-Service profile cannot change the ticket status.
        $this->login('post-only', 'postonly');
        $this->assertFalse(Ticket::isAllowedStatus(Ticket::WAITING, Ticket::PLANNED));
        $this->answer($approved, CommonITILValidation::ACCEPTED);
        $this->answer($rejected, CommonITILValidation::REFUSED);

        $this->assertSame(Ticket::PLANNED, $this->ticketStatus($approved));
        $this->assertSame(InstanceStatus::COMPLETED, $this->getInstance($rejected)->getStatus());
        $this->assertSame(Ticket::ASSIGNED, $this->ticketStatus($rejected));
    }

    /**
     * START, TASK on pending, END.
     *
     * @return array{ITILCategory, WorkflowVersion, Workflow}
     */
    private function publishPendingTaskToEnd(bool $solve): array
    {
        return $this->publishGraph(function (WorkflowVersion $draft, Node $start) use ($solve): void {
            $task = $this->createNode($draft, NodeType::TASK, ['ticket_status' => Ticket::WAITING]);
            $end  = $this->createNode($draft, NodeType::END, ['solve_ticket' => $solve ? 1 : 0]);
            $this->createTransition($start, $task, TransitionCondition::ALWAYS);
            $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        });
    }

    /**
     * Adds a step whose status is normalized, which createItem() would
     * report as a mismatch.
     */
    private function addNode(WorkflowVersion $draft, NodeType $type, int $status): Node
    {
        $node = new Node();
        $this->assertNotFalse($node->add([
            Node::$items_id => $draft->getID(),
            'name'          => $this->getUniqueString(),
            'node_type'     => $type->value,
            'ticket_status' => $status,
        ]));

        return $node;
    }

    private function completeActiveTask(Ticket $ticket): void
    {
        $task = $this->getActiveTask($this->getInstance($ticket));
        $this->updateItem(TicketTask::class, $task->getID(), ['state' => Planning::DONE]);
    }

    private function answer(Ticket $ticket, int $status): void
    {
        $validation = $this->getActiveValidation($this->getInstance($ticket));
        $this->assertTrue((new TicketValidation())->update([
            'id'                 => $validation->getID(),
            'status'             => $status,
            'comment_validation' => 'Answer',
        ]));
    }

    private function statusOf(Node $node): int
    {
        return (int) $this->reloadItem($node)->fields['ticket_status'];
    }

    private function ticketStatus(Ticket $ticket): int
    {
        return (int) $this->reloadItem($ticket)->fields['status'];
    }

    private function createUser(): User
    {
        return $this->createItem(User::class, ['name' => $this->getUniqueString()]);
    }
}
