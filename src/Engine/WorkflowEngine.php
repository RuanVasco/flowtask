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

namespace GlpiPlugin\Flowtask\Engine;

use CommonDBTM;
use CommonITILValidation;
use DBmysql;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Enum\MissingManagerBehavior;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use ITILFollowup;
use ITILSolution;
use Planning;
use Session;
use Ticket;
use TicketTask;
use TicketValidation;

/**
 * Runs workflow instances on tickets (spec 001, engine rules, AC-3 to AC-8
 * and AC-12 to AC-14).
 */
final readonly class WorkflowEngine
{
    /** Steps entered in a row without waiting for a user before the instance is flagged stuck. */
    public const MAX_STEPS_PER_RUN = 50;

    public function __construct(
        private WorkflowMatcher $matcher,
        private ActorResolver $resolver,
        private StepItemFactory $factory,
    ) {}

    public static function fromDb(DBmysql $db): self
    {
        return new self(new WorkflowMatcher(), new ActorResolver($db), new StepItemFactory());
    }

    /**
     * Starts the workflow of the ticket category, unless the ticket already
     * has an instance (AC-3, AC-12).
     */
    public function start(Ticket $ticket): ?Instance
    {
        if (Instance::getForTicket($ticket->getID()) instanceof Instance) {
            return null;
        }

        $version = $this->matcher->findVersion($ticket);
        $start   = $version instanceof WorkflowVersion ? $this->findStartNode($version) : null;
        if (!$start instanceof Node) {
            return null;
        }

        $instance = new Instance();
        if ($instance->add([
            'tickets_id'                          => $ticket->getID(),
            'plugin_flowtask_workflowversions_id' => $start->fields['plugin_flowtask_workflowversions_id'],
            'status'                              => InstanceStatus::RUNNING->value,
        ]) === false) {
            return null;
        }

        $this->run($instance, $ticket, $start, TransitionCondition::ALWAYS);

        return $instance;
    }

    /**
     * A ticket task set to done ends its step (AC-5).
     */
    public function handleTaskUpdate(TicketTask $task): void
    {
        if (in_array('state', $task->updates, true) && Fields::int($task->fields, 'state') === Planning::DONE) {
            $this->advance(TicketTask::class, $task->getID(), TransitionCondition::COMPLETED);
        }
    }

    /**
     * An answered validation ends its step (AC-6).
     */
    public function handleValidationUpdate(TicketValidation $validation): void
    {
        if (!in_array('status', $validation->updates, true)) {
            return;
        }

        $condition = $this->getOutcome($validation);
        if ($condition instanceof TransitionCondition) {
            $this->advance(TicketValidation::class, $validation->getID(), $condition);
        }
    }

    /**
     * A public followup by the requester a task step is assigned to marks
     * that task done, which ends the step through the task hook (spec 003,
     * AC-4).
     */
    public function handleFollowupAdd(ITILFollowup $followup): void
    {
        $author = Fields::int($followup->fields, 'users_id');
        if (
            Fields::string($followup->fields, 'itemtype') !== Ticket::class
            || Fields::int($followup->fields, 'is_private') !== 0
            || $author <= 0
        ) {
            return;
        }

        $instance = Instance::getForTicket(Fields::int($followup->fields, 'items_id'));
        $step     = $instance instanceof Instance && $instance->getStatus() === InstanceStatus::RUNNING
            ? InstanceStep::getActiveForInstance($instance->getID())
            : null;
        $node = $step?->getNode();
        if (
            !$step instanceof InstanceStep
            || !$node instanceof Node
            || $node->getNodeType() !== NodeType::TASK
            || ActorType::tryFrom(Fields::string($node->fields, 'actor_type')) !== ActorType::REQUESTER
        ) {
            return;
        }

        $task = $this->loadStepItem($step);
        if (
            !$task instanceof TicketTask
            || Fields::int($task->fields, 'users_id_tech') !== $author
            || Fields::int($task->fields, 'state') === Planning::DONE
        ) {
            return;
        }

        $task->update(['id' => $task->getID(), 'state' => Planning::DONE]);
    }

    /**
     * Enters the current step of a stuck instance again.
     */
    public function retry(Instance $instance): bool
    {
        $step   = InstanceStep::getActiveForInstance($instance->getID());
        $node   = $step?->getNode();
        $ticket = $this->loadTicket($instance);
        if (
            $instance->getStatus() !== InstanceStatus::STUCK
            || !$step instanceof InstanceStep
            || !$node instanceof Node
            || !$ticket instanceof Ticket
        ) {
            return false;
        }

        $instance->update(['id' => $instance->getID(), 'status' => InstanceStatus::RUNNING->value, 'stuck_reason' => null]);

        // The step got its task or approval before it got stuck: follow its
        // answer if it has one, or keep waiting for it.
        $item = $this->loadStepItem($step);
        if ($item instanceof CommonDBTM) {
            $outcome = $this->getOutcome($item);
            if ($outcome instanceof TransitionCondition) {
                $this->run($instance, $ticket, $node, $outcome, $step, StepStatus::DONE);
            }

            return true;
        }

        $skipped = $this->enter($instance, $ticket, $node, $step);
        if ($skipped instanceof TransitionCondition) {
            $this->run($instance, $ticket, $node, $skipped, $step, StepStatus::SKIPPED);
        }

        return true;
    }

    public function cancel(Instance $instance): bool
    {
        if (!$instance->isActive()) {
            return false;
        }

        $step = InstanceStep::getActiveForInstance($instance->getID());
        $step?->update(['id' => $step->getID(), 'status' => StepStatus::CANCELLED->value, 'date_end' => Session::getCurrentTime()]);

        return $instance->update([
            'id'       => $instance->getID(),
            'status'   => InstanceStatus::CANCELLED->value,
            'date_end' => Session::getCurrentTime(),
        ]);
    }

    /**
     * Flags the instance stuck when the task or validation of its active step
     * is gone; nothing is recreated (AC-14).
     *
     * @return bool Whether the instance was flagged
     */
    public function detectLostItem(Instance $instance): bool
    {
        $step = InstanceStep::getActiveForInstance($instance->getID());
        if ($instance->getStatus() !== InstanceStatus::RUNNING || !$step instanceof InstanceStep) {
            return false;
        }

        if (Fields::int($step->fields, 'items_id') <= 0 || $this->loadStepItem($step) instanceof CommonDBTM) {
            return false;
        }

        $this->flagStuck($instance, sprintf(
            __('The task or approval of the step "%s" was removed.', 'flowtask'),
            $step->getNode()?->getName() ?? '',
        ));

        return true;
    }

    private function advance(string $itemtype, int $items_id, TransitionCondition $condition): void
    {
        $step     = InstanceStep::getActiveForItem($itemtype, $items_id);
        $instance = new Instance();
        if (
            !$step instanceof InstanceStep
            || !$instance->getFromDB(Fields::int($step->fields, InstanceStep::$items_id))
            || $instance->getStatus() !== InstanceStatus::RUNNING
        ) {
            return;
        }

        $node   = $step->getNode();
        $ticket = $this->loadTicket($instance);
        if (!$node instanceof Node || !$ticket instanceof Ticket) {
            return;
        }

        $this->run($instance, $ticket, $node, $condition, $step, StepStatus::DONE);
    }

    /**
     * Flags the instance of the ticket stuck when its active step lost its
     * task or approval (AC-14).
     */
    public function checkTicket(Ticket $ticket): void
    {
        $instance = Instance::getForTicket($ticket->getID());
        if ($instance instanceof Instance) {
            $this->detectLostItem($instance);
        }
    }

    /**
     * Follows transitions from a step until the instance waits for a user,
     * ends or gets stuck.
     *
     * @param InstanceStep|null $leaving The step being left. It is closed only
     *                                   once its transition is found, so a
     *                                   stuck instance stays on it and can be
     *                                   retried.
     */
    private function run(
        Instance $instance,
        Ticket $ticket,
        Node $from,
        TransitionCondition $condition,
        ?InstanceStep $leaving = null,
        StepStatus $leaving_status = StepStatus::DONE,
    ): void {
        for ($entered = 0; ; $entered++) {
            $node = $this->findTarget($from, $condition);
            if (!$node instanceof Node) {
                $this->flagStuck($instance, sprintf(
                    __('The step "%1$s" has no "%2$s" transition.', 'flowtask'),
                    $from->getName(),
                    $condition->label(),
                ));

                return;
            }

            if ($leaving instanceof InstanceStep) {
                $this->closeStep($leaving, $leaving_status, $condition);
            }

            if ($entered >= self::MAX_STEPS_PER_RUN) {
                $this->openStep($instance, $node);
                $this->flagStuck($instance, sprintf(
                    __('More than %d steps were entered in a row without waiting for anyone.', 'flowtask'),
                    self::MAX_STEPS_PER_RUN,
                ));

                return;
            }

            $leaving = $this->openStep($instance, $node);
            $skipped = $this->enter($instance, $ticket, $node, $leaving);
            if (!$skipped instanceof TransitionCondition) {
                return;
            }

            $from           = $node;
            $condition      = $skipped;
            $leaving_status = StepStatus::SKIPPED;
        }
    }

    /**
     * @return TransitionCondition|null The condition a skipped step follows
     *                                  (the caller closes the step), or null
     *                                  when the instance waits, ends or is stuck
     */
    private function enter(Instance $instance, Ticket $ticket, Node $node, InstanceStep $step): ?TransitionCondition
    {
        $type = $node->getNodeType();

        if ($type === NodeType::END) {
            $this->closeStep($step, StepStatus::DONE, null);
            $this->complete($instance, $ticket, $node);

            return null;
        }

        if ($type !== NodeType::TASK && $type !== NodeType::APPROVAL) {
            $this->flagStuck($instance, sprintf(__('The step "%s" cannot be entered.', 'flowtask'), $node->getName()));

            return null;
        }

        $actor = $this->resolver->resolve($node, $ticket);
        if (!$actor->isResolved()) {
            $skip = $actor->manager_missing
                && Fields::string($node->fields, 'missing_manager_behavior') === MissingManagerBehavior::SKIP->value;
            if (!$skip) {
                $this->flagStuck($instance, $actor->failure);

                return null;
            }

            return TransitionCondition::onSkip($type);
        }

        $item = $this->factory->create($node, $ticket, $actor);
        if (is_string($item)) {
            $this->flagStuck($instance, $item);

            return null;
        }

        $step->update([
            'id'        => $step->getID(),
            'itemtype'  => $item::class,
            'items_id'  => $item->getID(),
            'users_id'  => $actor->getUserId(),
            'groups_id' => $actor->getGroupId(),
        ]);

        return null;
    }

    private function complete(Instance $instance, Ticket $ticket, Node $end): void
    {
        $instance->update([
            'id'       => $instance->getID(),
            'status'   => InstanceStatus::COMPLETED->value,
            'date_end' => Session::getCurrentTime(),
        ]);

        $ticket->getFromDB($ticket->getID());
        if (Fields::int($end->fields, 'solve_ticket') !== 1 || $ticket->isSolved(true)) {
            return;
        }

        $solved = (new ITILSolution())->add([
            'itemtype' => Ticket::class,
            'items_id' => $ticket->getID(),
            'content'  => htmlescape(sprintf(
                __('Solved by the workflow "%s".', 'flowtask'),
                $this->getWorkflowName($instance),
            )),
        ]);
        if ($solved === false) {
            Session::addMessageAfterRedirect(
                __s('The workflow ended but the ticket could not be solved.', 'flowtask'),
                false,
                WARNING,
            );
        }
    }

    private function openStep(Instance $instance, Node $node): InstanceStep
    {
        $step = new InstanceStep();
        $step->add([
            InstanceStep::$items_id    => $instance->getID(),
            'plugin_flowtask_nodes_id' => $node->getID(),
            'status'                   => StepStatus::ACTIVE->value,
        ]);

        return $step;
    }

    private function closeStep(InstanceStep $step, StepStatus $status, ?TransitionCondition $outcome): void
    {
        $step->update([
            'id'       => $step->getID(),
            'status'   => $status->value,
            'outcome'  => $outcome?->value,
            'date_end' => Session::getCurrentTime(),
        ]);
    }

    private function flagStuck(Instance $instance, string $reason): void
    {
        $instance->update([
            'id'           => $instance->getID(),
            'status'       => InstanceStatus::STUCK->value,
            'stuck_reason' => $reason,
        ]);
        // The automatic action may run during another user's request.
        if (Session::isCron()) {
            return;
        }

        Session::addMessageAfterRedirect(
            htmlescape(sprintf(__('The workflow of this ticket is stuck: %s', 'flowtask'), $reason)),
            false,
            WARNING,
        );
    }

    private function findStartNode(WorkflowVersion $version): ?Node
    {
        $node = new Node();

        return $node->getFromDBByCrit([
            Node::$items_id => $version->getID(),
            'node_type'     => NodeType::START->value,
        ]) ? $node : null;
    }

    private function findTarget(Node $from, TransitionCondition $condition): ?Node
    {
        $transition = new Transition();
        $target     = new Node();

        return $transition->getFromDBByCrit([
            'plugin_flowtask_nodes_id_source' => $from->getID(),
            'trigger_condition'               => $condition->value,
        ]) && $target->getFromDB(Fields::int($transition->fields, 'plugin_flowtask_nodes_id_target'))
            ? $target
            : null;
    }

    private function loadStepItem(InstanceStep $step): ?CommonDBTM
    {
        $itemtype = Fields::string($step->fields, 'itemtype');
        $item     = $itemtype !== '' ? getItemForItemtype($itemtype) : false;

        return $item instanceof CommonDBTM && $item->getFromDB(Fields::int($step->fields, 'items_id')) ? $item : null;
    }

    /**
     * Transition fired by the current state of a step item, if any.
     */
    private function getOutcome(CommonDBTM $item): ?TransitionCondition
    {
        if ($item instanceof TicketTask) {
            return Fields::int($item->fields, 'state') === Planning::DONE ? TransitionCondition::COMPLETED : null;
        }

        return match ($item instanceof TicketValidation ? Fields::int($item->fields, 'status') : 0) {
            CommonITILValidation::ACCEPTED => TransitionCondition::APPROVED,
            CommonITILValidation::REFUSED  => TransitionCondition::REJECTED,
            default                        => null,
        };
    }

    private function loadTicket(Instance $instance): ?Ticket
    {
        $ticket = new Ticket();

        return $ticket->getFromDB(Fields::int($instance->fields, Instance::$items_id)) ? $ticket : null;
    }

    private function getWorkflowName(Instance $instance): string
    {
        $version  = new WorkflowVersion();
        $workflow = new Workflow();

        return $version->getFromDB(Fields::int($instance->fields, 'plugin_flowtask_workflowversions_id'))
            && $workflow->getFromDB(Fields::int($version->fields, WorkflowVersion::$items_id))
            ? $workflow->getName()
            : '';
    }
}
