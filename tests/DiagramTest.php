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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Controller\GraphController;
use GlpiPlugin\Flowtask\Diagram;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\View\DiagramView;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Planning;
use Ticket;
use TicketTask;

/**
 * Diagram viewer, spec 002 stage 1 (AC-7 viewer side, AC-8, AC-9).
 */
final class DiagramTest extends DbTestCase
{
    use InstanceFixtures;

    public function testVersionGraphListsStepsAndTransitions(): void
    {
        $this->login();
        $draft = $this->createPublishableDraft();
        $task  = $this->getNodeOfType($draft, NodeType::TASK);
        $this->updateItem(Node::class, $task->getID(), ['position_x' => 120, 'position_y' => 40]);

        $graph = $this->versionGraph($draft);

        $this->assertSame(['start', 'task', 'end'], array_column($graph['nodes'], 'type'));
        $this->assertSame([0, 120, 0], array_column($graph['nodes'], 'x'));
        $this->assertSame([0, 40, 0], array_column($graph['nodes'], 'y'));
        $this->assertSame([null, null, null], array_column($graph['nodes'], 'state'));
        $this->assertSame(NodeType::TASK->icon(), $graph['nodes'][1]['icon']);
        $this->assertSame($task->getActorLabel(), $graph['nodes'][1]['actor']);
        $this->assertSame(['always', 'completed'], array_column($graph['edges'], 'condition'));
        $this->assertSame($task->getID(), $graph['edges'][0]['target']);
        $this->assertSame([false, false], array_column($graph['edges'], 'taken'));
    }

    public function testVersionGraphNeedsTheRightToViewTheWorkflow(): void
    {
        $this->login();
        $draft = $this->createPublishableDraft();

        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = 0;

        $this->expectException(AccessDeniedHttpException::class);
        $this->versionGraph($draft);
    }

    public function testInstanceGraphMarksTheActiveAndPassedSteps(): void
    {
        $this->login();
        [$category, $version] = $this->publishLinear();
        $ticket   = $this->createTicket($category);
        $instance = $this->getInstance($ticket);

        $running = $this->instanceGraph($instance);
        $this->assertSame(['done', 'active', null], array_column($running['nodes'], 'state'));
        $this->assertSame([true, false], array_column($running['edges'], 'taken'));

        $step = InstanceStep::getActiveForInstance($instance->getID());
        $this->assertInstanceOf(InstanceStep::class, $step);
        $this->updateItem(TicketTask::class, (int) $step->fields['items_id'], ['state' => Planning::DONE]);

        $completed = $this->instanceGraph($instance);
        $this->assertSame(['done', 'done', 'done'], array_column($completed['nodes'], 'state'));
        $this->assertSame([true, true], array_column($completed['edges'], 'taken'));
        $this->assertSame(
            array_column($this->versionGraph($version)['nodes'], 'id'),
            array_column($completed['nodes'], 'id'),
        );
    }

    public function testInstanceGraphFollowsTheRightToViewTheTicket(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $instance = $this->getInstance($this->createTicket($category));

        // The workflow right is not needed: the ticket tab is for everyone who sees the ticket.
        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = 0;
        $this->assertCount(3, $this->instanceGraph($instance)['nodes']);

        $_SESSION['glpiactiveprofile'][Ticket::$rightname] = 0;
        $this->expectException(AccessDeniedHttpException::class);
        $this->instanceGraph($instance);
    }

    public function testDiagramTabShowsTheCurrentVersion(): void
    {
        $this->login();
        $draft    = $this->createPublishableDraft();
        $workflow = $draft->getItem();
        $this->assertInstanceOf(Workflow::class, $workflow);

        $this->assertNotSame('', (new Diagram())->getTabNameForItem($workflow));
        $this->assertNotSame('', (new Diagram())->getTabNameForItem($draft));
        $this->assertSame('', (new Diagram())->getTabNameForItem(new Workflow()));

        ob_start();
        $this->assertTrue(Diagram::displayTabContentForItem($workflow));
        $html = (string) ob_get_clean();

        // The URL sits in the JSON configuration, where slashes are escaped.
        $this->assertStringContainsString(str_replace('/', '\\/', DiagramView::getVersionGraphURL($draft->getID())), $html);
    }

    public function testTicketTabShowsTheInstanceDiagram(): void
    {
        $this->login();
        [$category] = $this->publishLinear();
        $ticket = $this->createTicket($category);

        ob_start();
        $this->assertTrue(Instance::displayTabContentForItem($ticket));
        $html = (string) ob_get_clean();

        $url = DiagramView::getInstanceGraphURL($this->getInstance($ticket)->getID());
        $this->assertStringContainsString(str_replace('/', '\\/', $url), $html);
    }

    public function testDiagramLabelsComeTranslatedFromTheServer(): void
    {
        $config = DiagramView::context('/graph')['config'];
        $this->assertIsArray($config['strings']);

        $strings = $config['strings'];
        $this->assertSame(NodeType::APPROVAL->label(), $strings['types']['approval']);
        $this->assertSame(TransitionCondition::REJECTED->label(), $strings['conditions']['rejected']);
        $this->assertArrayHasKey('active', $strings['states']);
    }

    private function getNodeOfType(WorkflowVersion $version, NodeType $type): Node
    {
        foreach (Node::getForVersion($version->getID()) as $node) {
            if ($node->getNodeType() === $type) {
                return $node;
            }
        }

        $this->fail(sprintf('No %s step in version %d.', $type->value, $version->getID()));
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function versionGraph(WorkflowVersion $version): array
    {
        return $this->decode((new GraphController())->version($version->getID())->getContent());
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function instanceGraph(Instance $instance): array
    {
        return $this->decode((new GraphController())->instance($instance->getID())->getContent());
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function decode(string|false $json): array
    {
        $this->assertIsString($json);
        /** @var array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph */
        $graph = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $graph;
    }
}
