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
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Controller\InstanceActionController;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\View\InstanceTimeline;
use GlpiPlugin\Flowtask\Workflow;
use Planning;
use Session;
use Ticket;
use TicketTask;
use User;

/**
 * Ticket "Workflow" tab, retry and cancel, spec 001 stage 4 (ticket tab rules, AC-9).
 */
final class InstanceTabTest extends DbTestCase
{
    use InstanceFixtures;

    public function testTabIsShownOnlyOnTicketsRunningAWorkflow(): void
    {
        $this->login();
        [$category] = $this->publishLinear();

        $this->assertNotSame('', (new Instance())->getTabNameForItem($this->createTicket($category)));
        $this->assertSame('', (new Instance())->getTabNameForItem($this->createTicket(null)));
    }

    public function testTimelineListsEnteredStepsInOrder(): void
    {
        $this->login();
        [$category, , $workflow] = $this->publishLinear();
        $ticket   = $this->createTicket($category);
        $instance = $this->getInstance($ticket);
        $task_id  = $this->getActiveItemId($instance);

        $running = $this->timeline($instance, $ticket);
        $this->assertSame('Running', $running['status_label']);
        $this->assertSame([true], array_column($running['steps'], 'is_active'));

        $this->updateItem(TicketTask::class, $task_id, ['state' => Planning::DONE]);
        $data = $this->timeline($this->reloadItem($instance), $ticket);

        $this->assertSame($workflow->getName(), $data['workflow_name']);
        $this->assertSame($workflow->getLinkURL(), $data['workflow_url']);
        $this->assertSame(1, $data['version_number']);
        $this->assertSame('Completed', $data['status_label']);
        $this->assertNotSame('', $data['date_end']);
        $this->assertSame(['Task', 'End'], array_column($data['steps'], 'type_label'));
        $this->assertSame(['Done', 'Done'], array_column($data['steps'], 'status_label'));
        $this->assertSame([false, false], array_column($data['steps'], 'is_active'));
        $this->assertSame('Completed', $data['steps'][0]['outcome']);
        $this->assertSame(User::getFriendlyNameById(Session::getLoginUserID()), $data['steps'][0]['actor']);
        $this->assertSame(sprintf('%s #%d', TicketTask::getTypeName(1), $task_id), $data['steps'][0]['item']);
        $this->assertFalse($data['can_retry']);
        $this->assertFalse($data['can_cancel']);
    }

    public function testTabRendersTheTimeline(): void
    {
        $this->login();
        [$category, , $workflow] = $this->publishLinear();
        $ticket = $this->createTicket($category);

        ob_start();
        $this->assertTrue(Instance::displayTabContentForItem($ticket));
        $html = (string) ob_get_clean();

        $this->assertStringContainsString($workflow->getName(), $html);
        $this->assertStringContainsString(Instance::getActionURL($this->getInstance($ticket)->getID(), Instance::ACTION_CANCEL), $html);
    }

    public function testStuckInstanceOffersRetryAndCancelPerRight(): void
    {
        $this->login();
        $actor  = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'is_active' => 0]);
        [$category] = $this->publishLinear(users_id: $actor->getID());
        $ticket   = $this->createTicket($category);
        $instance = $this->getInstance($ticket);

        $data = $this->timeline($instance, $ticket);
        $this->assertSame('Stuck', $data['status_label']);
        $this->assertNotSame('', $data['stuck_reason']);
        $this->assertTrue($data['can_retry']);
        $this->assertTrue($data['can_cancel']);
        $this->hasSessionMessages(WARNING, [htmlescape('The workflow of this ticket is stuck: ' . $data['stuck_reason'])]);

        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = READ;
        $data = $this->timeline($instance, $ticket);
        $this->assertTrue($data['can_retry']);
        $this->assertFalse($data['can_cancel']);
    }

    public function testRetryActionResumesOnceTheActorIsBack(): void
    {
        $this->login();
        $actor  = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'is_active' => 0]);
        [$category] = $this->publishLinear(users_id: $actor->getID());
        $instance   = $this->getInstance($this->createTicket($category));
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        (new InstanceActionController())->retry($instance->getID());
        $this->assertSame(InstanceStatus::STUCK, $this->reloadItem($instance)->getStatus());
        $this->hasNoSessionMessage(INFO);

        $this->updateItem(User::class, $actor->getID(), ['is_active' => 1]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        (new InstanceActionController())->retry($instance->getID());

        $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($instance)->getStatus());
        $this->hasSessionMessages(INFO, ['The workflow has been resumed.']);

        (new InstanceActionController())->retry($instance->getID());
        $this->hasSessionMessages(WARNING, ['This action no longer applies to the workflow of this ticket.']);
    }

    public function testRetryActionNeedsTheRightToUpdateTheTicket(): void
    {
        $this->login();
        $actor  = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'is_active' => 0]);
        [$category] = $this->publishLinear(users_id: $actor->getID());
        $ticket     = $this->createTicket($category);
        $instance   = $this->getInstance($ticket);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        // Requesters can update a ticket nobody has worked on, but not retry its workflow.
        $_SESSION['glpiactiveprofile'][Ticket::$rightname] = READ;
        $this->assertFalse($this->timeline($instance, $ticket)['can_retry']);

        $this->expectException(AccessDeniedHttpException::class);
        (new InstanceActionController())->retry($instance->getID());
    }

    public function testCancelActionNeedsTheWorkflowRightAndAppliesOnce(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $instance   = $this->getInstance($this->createTicket($category));

        $right = $_SESSION['glpiactiveprofile'][Workflow::$rightname];
        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = READ;
        try {
            (new InstanceActionController())->cancel($instance->getID());
            $this->fail('Cancelling without the update right on workflows must be refused.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame(InstanceStatus::RUNNING, $this->reloadItem($instance)->getStatus());
        }

        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = $right;
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        (new InstanceActionController())->cancel($instance->getID());

        $this->assertSame(InstanceStatus::CANCELLED, $this->reloadItem($instance)->getStatus());
        $this->hasSessionMessages(INFO, ['The workflow has been cancelled.']);

        (new InstanceActionController())->cancel($instance->getID());
        $this->hasSessionMessages(WARNING, ['This action no longer applies to the workflow of this ticket.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function timeline(Instance $instance, Ticket $ticket): array
    {
        /** @var DBmysql $DB */
        global $DB;

        return (new InstanceTimeline($DB))->build($instance, $ticket);
    }

    private function getActiveItemId(Instance $instance): int
    {
        $step = InstanceStep::getActiveForInstance($instance->getID());
        $this->assertInstanceOf(InstanceStep::class, $step);

        return (int) $step->fields['items_id'];
    }
}
