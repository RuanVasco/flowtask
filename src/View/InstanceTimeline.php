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

namespace GlpiPlugin\Flowtask\View;

use DBmysql;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Group;
use Ticket;
use User;

/**
 * Data of the "Workflow" tab of a ticket: the instance header and its
 * timeline (spec 001, ticket tab rules, AC-9).
 */
final readonly class InstanceTimeline
{
    public function __construct(private DBmysql $db) {}

    /**
     * @return array{
     *     workflow_name: string,
     *     workflow_url: string|null,
     *     workflow_icon: string,
     *     version_number: int,
     *     status_label: string,
     *     status_badge: string,
     *     stuck_reason: string,
     *     date_start: string,
     *     date_end: string,
     *     steps: list<array<string, mixed>>,
     *     can_retry: bool,
     *     can_cancel: bool,
     *     retry_url: string,
     *     cancel_url: string,
     * }
     */
    public function build(Instance $instance, Ticket $ticket): array
    {
        $version  = new WorkflowVersion();
        $workflow = new Workflow();
        $has_version  = $version->getFromDB(Fields::int($instance->fields, 'plugin_flowtask_workflowversions_id'));
        $has_workflow = $has_version && $workflow->getFromDB(Fields::int($version->fields, WorkflowVersion::$items_id));
        $status       = $instance->getStatus();

        return [
            'workflow_name'  => $has_workflow ? $workflow->getName() : '',
            'workflow_url'   => $has_workflow && Workflow::canView() && $workflow->canViewItem() ? $workflow->getLinkURL() : null,
            'workflow_icon'  => Workflow::getIcon(),
            'version_number' => $has_version ? Fields::int($version->fields, 'version_number') : 0,
            'status_label'   => $status?->label() ?? '',
            'status_badge'   => $this->instanceBadge($status),
            'stuck_reason'   => $status === InstanceStatus::STUCK ? Fields::string($instance->fields, 'stuck_reason') : '',
            'date_start'     => Fields::string($instance->fields, 'date_creation'),
            'date_end'       => Fields::string($instance->fields, 'date_end'),
            'steps'          => $this->buildSteps($instance, $has_version ? $version->getID() : 0),
            'can_retry'      => $status === InstanceStatus::STUCK && Instance::canRetryOn($ticket),
            'can_cancel'     => $instance->isActive() && Instance::canCancelOn($ticket),
            'retry_url'      => Instance::getActionURL($instance->getID(), Instance::ACTION_RETRY),
            'cancel_url'     => Instance::getActionURL($instance->getID(), Instance::ACTION_CANCEL),
        ];
    }

    /**
     * @return list<array<string, mixed>> Steps in the order they were entered
     */
    private function buildSteps(Instance $instance, int $versions_id): array
    {
        $nodes = [];
        foreach (Node::getForVersion($versions_id) as $node) {
            $nodes[$node->getID()] = $node;
        }

        $steps = [];
        $rows  = $this->db->request([
            'FROM'  => InstanceStep::getTable(),
            'WHERE' => [InstanceStep::$items_id => $instance->getID()],
            'ORDER' => 'id',
        ]);
        foreach ($rows as $row) {
            $node    = $nodes[Fields::int($row, 'plugin_flowtask_nodes_id')] ?? null;
            $type    = $node?->getNodeType();
            $status  = StepStatus::tryFrom(Fields::string($row, 'status'));
            $outcome = TransitionCondition::tryFrom(Fields::string($row, 'outcome'));

            $steps[] = [
                'name'         => $node?->getName() ?? '',
                'type_label'   => $type?->label() ?? '',
                'icon'         => $type?->icon() ?? 'ti ti-point',
                'status_label' => $status?->label() ?? '',
                'status_badge' => $this->stepBadge($status),
                'is_active'    => $status === StepStatus::ACTIVE,
                'outcome'      => $outcome?->label() ?? '',
                'actor'        => $this->actorName($row),
                'item'         => $this->itemName($row),
                'date_start'   => Fields::string($row, 'date_creation'),
                'date_end'     => Fields::string($row, 'date_end'),
            ];
        }

        return $steps;
    }

    private function actorName(mixed $row): string
    {
        $users_id  = Fields::int($row, 'users_id');
        $groups_id = Fields::int($row, 'groups_id');

        return match (true) {
            $users_id > 0  => User::getFriendlyNameById($users_id),
            $groups_id > 0 => Group::getFriendlyNameById($groups_id),
            default        => '',
        };
    }

    /**
     * Type and id of the task or approval created by the step, e.g. "Ticket task #12".
     */
    private function itemName(mixed $row): string
    {
        $itemtype = Fields::string($row, 'itemtype');
        $items_id = Fields::int($row, 'items_id');
        $item     = $itemtype !== '' && $items_id > 0 ? getItemForItemtype($itemtype) : false;

        return $item !== false ? sprintf(__('%1$s #%2$d', 'flowtask'), $item::getTypeName(1), $items_id) : '';
    }

    private function instanceBadge(?InstanceStatus $status): string
    {
        return match ($status) {
            InstanceStatus::RUNNING   => 'bg-blue-lt',
            InstanceStatus::COMPLETED => 'bg-green-lt',
            InstanceStatus::STUCK     => 'bg-orange-lt',
            default                   => 'bg-secondary-lt',
        };
    }

    private function stepBadge(?StepStatus $status): string
    {
        return match ($status) {
            StepStatus::ACTIVE  => 'bg-blue-lt',
            StepStatus::DONE    => 'bg-green-lt',
            StepStatus::SKIPPED => 'bg-yellow-lt',
            default             => 'bg-secondary-lt',
        };
    }
}
