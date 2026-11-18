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

use Glpi\Controller\GenericFormController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Controller\DiagramEditController;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Diagram editor endpoints, spec 002 stage 2 (AC-2, AC-3, AC-5, AC-7).
 */
final class DiagramEditTest extends DbTestCase
{
    use WorkflowFixtures;

    public function testStepAddedFromThePaletteIsSavedWhereItWasDropped(): void
    {
        $this->login();
        $draft = $this->createDraft();

        $response = $this->controller()->addNode($draft->getID(), $this->json(['type' => 'task', 'name' => '', 'x' => 120, 'y' => 80]));
        $data     = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($data['node']);
        $this->assertSame('task', $data['node']['type']);
        $this->assertSame(NodeType::TASK->label(), $data['node']['name'], 'Named after its type');
        $this->assertSame([120, 80], [$data['node']['x'], $data['node']['y']]);

        // Saved incomplete: the actor and task template come later (spec 002).
        $node = new Node();
        $this->assertTrue($node->getFromDB($data['node']['id']));
        $this->assertSame($draft->getID(), (int) $node->fields['plugin_flowtask_workflowversions_id']);
        $this->assertHasNoSessionMessages();
    }

    public function testStartStepCannotBeAddedFromTheDiagram(): void
    {
        $this->login();
        $draft = $this->createDraft();

        $response = $this->controller()->addNode($draft->getID(), $this->json(['type' => 'start', 'x' => 0, 'y' => 0]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['The start step is created with the draft and cannot be added.'], $this->decode($response)['errors']);
        $this->assertHasNoSessionMessages();
    }

    public function testMessagesQueuedForAnotherPageStayQueued(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['Saved in another tab']];

        $response = $this->controller()->addNode($draft->getID(), $this->json(['type' => 'start', 'x' => 0, 'y' => 0]));

        $this->assertSame(['The start step is created with the draft and cannot be added.'], $this->decode($response)['errors']);
        $this->assertSame([INFO => ['Saved in another tab']], $_SESSION['MESSAGE_AFTER_REDIRECT']);
    }

    public function testMovedStepsKeepTheirPosition(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);
        $task  = $this->createNode($draft, NodeType::TASK);

        $response = $this->controller()->savePositions($draft->getID(), $this->json(['positions' => [
            ['id' => $start->getID(), 'x' => 16, 'y' => 24],
            ['id' => $task->getID(), 'x' => 300, 'y' => 40],
        ]]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([16, 24], $this->position($start));
        $this->assertSame([300, 40], $this->position($task));
    }

    public function testPositionsOfAnotherVersionAreIgnored(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $other = $this->getStartNode($this->createDraft());

        $this->controller()->savePositions($draft->getID(), $this->json(['positions' => [
            ['id' => $other->getID(), 'x' => 300, 'y' => 40],
        ]]));

        $this->assertSame([0, 0], $this->position($other));
    }

    public function testConnectingStepsCreatesTheTransition(): void
    {
        $this->login();
        $draft    = $this->createDraft();
        $approval = $this->createNode($draft, NodeType::APPROVAL);
        $end      = $this->createNode($draft, NodeType::END);

        $response = $this->controller()->addTransition($draft->getID(), $this->json([
            'source'    => $approval->getID(),
            'target'    => $end->getID(),
            'condition' => TransitionCondition::REJECTED->value,
        ]));
        $data = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($data['edge']);
        $this->assertSame(
            [$approval->getID(), $end->getID(), 'rejected'],
            [$data['edge']['source'], $data['edge']['target'], $data['edge']['condition']],
        );
        $this->assertSame(1, countElementsInTable(Transition::getTable(), ['plugin_flowtask_nodes_id_source' => $approval->getID()]));
    }

    public function testRefusedConnectionIsNotCreatedAndSaysWhy(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);
        $task  = $this->createNode($draft, NodeType::TASK);
        $this->createTransition($start, $task, TransitionCondition::ALWAYS);

        $response = $this->controller()->addTransition($draft->getID(), $this->json([
            'source'    => $task->getID(),
            'target'    => $start->getID(),
            'condition' => TransitionCondition::COMPLETED->value,
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['A transition cannot enter the start step.'], $this->decode($response)['errors']);
        $this->assertSame(1, countElementsInTable(Transition::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
        $this->assertHasNoSessionMessages();
    }

    public function testDeletingAStepRemovesItsTransitions(): void
    {
        $this->login();
        $draft = $this->createPublishableDraft();
        $task  = new Node();
        $this->assertTrue($task->getFromDBByCrit([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'node_type'                           => NodeType::TASK->value,
        ]));

        $response = $this->controller()->deleteNode($task->getID());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse((new Node())->getFromDB($task->getID()));
        $this->assertSame(0, countElementsInTable(Transition::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
    }

    public function testStartStepCannotBeDeleted(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);

        $response = $this->controller()->deleteNode($start->getID());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['The start step cannot be deleted.'], $this->decode($response)['errors']);
        $this->assertTrue((new Node())->getFromDB($start->getID()));
    }

    public function testDeletingATransition(): void
    {
        $this->login();
        $draft      = $this->createDraft();
        $task       = $this->createNode($draft, NodeType::TASK);
        $transition = $this->createTransition($this->getStartNode($draft), $task, TransitionCondition::ALWAYS);

        $this->assertSame(200, $this->controller()->deleteTransition($transition->getID())->getStatusCode());
        $this->assertFalse((new Transition())->getFromDB($transition->getID()));
    }

    public function testPublishedVersionRefusesEveryChange(): void
    {
        $this->login();
        $draft = $this->createPublishableDraft();
        $this->assertSame([], $this->publish($draft));
        $start      = $this->getStartNode($draft);
        $transition = new Transition();
        $this->assertTrue($transition->getFromDBByCrit(['plugin_flowtask_nodes_id_source' => $start->getID()]));

        $responses = [
            $this->controller()->addNode($draft->getID(), $this->json(['type' => 'end', 'x' => 1, 'y' => 1])),
            $this->controller()->savePositions($draft->getID(), $this->json(['positions' => [['id' => $start->getID(), 'x' => 9, 'y' => 9]]])),
            $this->controller()->addTransition($draft->getID(), $this->json(['source' => 0, 'target' => 0, 'condition' => 'always'])),
            $this->controller()->deleteNode($start->getID()),
            $this->controller()->deleteTransition($transition->getID()),
        ];

        foreach ($responses as $response) {
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame(['Steps and transitions can only be changed in a draft version.'], $this->decode($response)['errors']);
        }

        $this->assertSame([0, 0], $this->position($start));
        $this->assertTrue((new Transition())->getFromDB($transition->getID()));
        $this->assertSame(3, countElementsInTable(Node::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
    }

    /**
     * The step and transition forms are gone; GLPI's generic item pages that
     * would replace them are closed (spec 002, AC-12).
     *
     * @return iterable<string, array{class-string<Node|Transition>, string}>
     */
    public static function formActionProvider(): iterable
    {
        foreach ([Node::class, Transition::class] as $class) {
            foreach (['add', 'update', 'purge'] as $action) {
                yield sprintf('%s %s', $class, $action) => [$class, $action];
            }
        }
    }

    /**
     * @param class-string<Node|Transition> $class
     */
    #[DataProvider('formActionProvider')]
    public function testOldFormURLsChangeNothing(string $class, string $action): void
    {
        $this->login();
        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);
        $end   = $this->createNode($draft, NodeType::END);
        $item  = $class === Node::class ? $end : $this->createTransition($start, $end, TransitionCondition::ALWAYS);
        $count = countElementsInTable($class::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]);

        $request = Request::create('/', 'POST', [
            $action                               => 1,
            'id'                                  => $action === 'add' ? 0 : $item->getID(),
            'name'                                => 'Changed',
            'node_type'                           => NodeType::END->value,
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
        ]);
        $request->attributes->set('class', $class);

        try {
            (new GenericFormController())($request);
            $this->fail('The generic form must be closed.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame($count, countElementsInTable($class::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
            $this->assertNotSame('Changed', $this->reloadItem($item)->fields['name'] ?? null);
        }
    }

    /**
     * The API, massive actions and every other generic entry point check
     * these rights (spec 002, AC-12).
     */
    public function testGenericEntryPointsAreClosed(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);
        $end   = $this->createNode($draft, NodeType::END);

        foreach ([Node::class, Transition::class] as $class) {
            $this->assertFalse($class::canView());
            $this->assertFalse($class::canCreate());
            $this->assertFalse($class::canUpdate());
            $this->assertFalse($class::canDelete());
            $this->assertFalse($class::canPurge());
        }

        $this->assertFalse($end->can($end->getID(), UPDATE));
        $this->assertFalse($end->can($end->getID(), PURGE));
        $input = [
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'plugin_flowtask_nodes_id_source'     => $start->getID(),
            'plugin_flowtask_nodes_id_target'     => $end->getID(),
        ];
        $this->assertFalse((new Transition())->can(-1, CREATE, $input));
    }

    public function testStepsAndTransitionsHaveNoTabsAnyMore(): void
    {
        $this->login();
        $draft    = $this->createDraft();
        $workflow = $draft->getItem();
        $this->assertInstanceOf(Workflow::class, $workflow);

        foreach ([$workflow, $draft] as $item) {
            $tabs = array_keys($item->defineAllTabs());
            $this->assertSame([], array_filter(
                $tabs,
                static fn(string $tab): bool => str_starts_with($tab, Node::class) || str_starts_with($tab, Transition::class),
            ));
        }
    }

    public function testChangesNeedTheUpdateRightOnWorkflows(): void
    {
        $this->login();
        $draft = $this->createDraft();

        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = READ;

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()->addNode($draft->getID(), $this->json(['type' => 'end', 'x' => 1, 'y' => 1]));
    }

    private function controller(): DiagramEditController
    {
        return new DiagramEditController();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): Request
    {
        return Request::create('/', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        $content = $response->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array{int, int}
     */
    private function position(Node $node): array
    {
        $node = $this->reloadItem($node);

        return [(int) $node->fields['position_x'], (int) $node->fields['position_y']];
    }

    /**
     * The endpoints answer with the messages; none is left for the next page.
     */
    private function assertHasNoSessionMessages(): void
    {
        $this->assertSame([], array_filter($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []));
    }
}
