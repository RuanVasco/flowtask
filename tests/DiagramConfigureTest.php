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
use GlpiPlugin\Flowtask\Controller\DiagramEditController;
use GlpiPlugin\Flowtask\Controller\GraphController;
use GlpiPlugin\Flowtask\Controller\PublishVersionController;
use GlpiPlugin\Flowtask\Diagram;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Enum\VersionStatus;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TaskTemplate;
use User;

/**
 * Side panel, warning markers, toolbar and publication from the diagram,
 * spec 002 stage 3 (AC-1, AC-4, AC-6, AC-10).
 */
final class DiagramConfigureTest extends DbTestCase
{
    use WorkflowFixtures;

    public function testSidePanelSavesTheStep(): void
    {
        $this->login();
        $draft    = $this->createDraft();
        $task     = $this->addFromPalette($draft->getID(), NodeType::TASK);
        $template = $this->createItem(TaskTemplate::class, ['name' => 'Prepare', 'content' => 'Content']);
        $this->assertNotSame([], $task['problems'], 'An incomplete step shows a marker');

        $data = $this->decode($this->controller()->updateNode($task['id'], $this->json([
            'name'             => 'Prepare equipment',
            'node_type'        => NodeType::TASK->value,
            'actor_type'       => ActorType::USER->value,
            'users_id'         => Session::getLoginUserID(),
            'groups_id'        => 5,
            'tasktemplates_id' => $template->getID(),
            'solve_ticket'     => 1,
        ])));

        $this->assertIsArray($data['node']);
        $node = $data['node'];
        $this->assertSame('Prepare equipment', $node['name']);
        $this->assertSame([], $node['problems']);
        $this->assertIsArray($node['config']);
        $this->assertSame(User::getFriendlyNameById(Session::getLoginUserID()), $node['config']['users_name']);
        $this->assertSame('Prepare', $node['config']['tasktemplates_name']);
        // Normalized as in the step form: fields that do not apply are reset.
        $this->assertSame(0, $node['config']['groups_id']);
        $this->assertFalse($node['config']['solve_ticket']);
    }

    public function testOnlyEditorsGetTheProblemsAndPanelValues(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $this->addFromPalette($draft->getID(), NodeType::TASK);

        $graph = $this->decode((new GraphController())->version($draft->getID()));
        $this->assertIsArray($graph['nodes']);
        $this->assertSame([[], ['The step needs an actor.', 'A task step needs a task template.']], array_column($graph['nodes'], 'problems'));

        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = READ;
        $graph = $this->decode((new GraphController())->version($draft->getID()));
        $this->assertIsArray($graph['nodes']);
        $this->assertSame([], array_column($graph['nodes'], 'problems'));
    }

    public function testRefusedPanelChangeKeepsTheSavedValues(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);

        $response = $this->controller()->updateNode($start->getID(), $this->json(['name' => 'Renamed']));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame(['The start step cannot be edited.'], $this->decode($response)['errors']);
        $this->assertSame($start->getName(), $this->reloadItem($start)->getName());
    }

    public function testChangingTheTypeDropsTransitionsAndWarns(): void
    {
        $this->login();
        $draft    = $this->createDraft();
        $approval = $this->createNode($draft, NodeType::APPROVAL);
        $end      = $this->createNode($draft, NodeType::END);
        $this->createTransition($approval, $end, TransitionCondition::APPROVED);

        $data = $this->decode($this->controller()->updateNode($approval->getID(), $this->json([
            'node_type' => NodeType::END->value,
        ])));

        $this->assertSame(['Transitions this step can no longer fire were removed.'], $data['warnings']);
        $this->assertSame(0, countElementsInTable(Transition::getTable(), ['plugin_flowtask_nodes_id_source' => $approval->getID()]));
    }

    public function testFailedPublicationPointsAtTheSteps(): void
    {
        $this->login();
        $draft = $this->createDraft();
        $task  = $this->addFromPalette($draft->getID(), NodeType::TASK);

        $response = (new PublishVersionController())($draft->getID(), $this->jsonRequest());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertIsArray($data['errors']);
        $this->assertContains('The version needs at least one end step.', $data['errors']);
        $this->assertIsArray($data['problems']);
        $nodes = array_column($data['problems'], 'node');
        $this->assertContains($task['id'], $nodes);
        $this->assertContains(0, $nodes, 'A problem of the whole graph points at no step');
        $this->assertTrue($this->reloadItem($draft)->isDraft());
    }

    public function testPublicationFromTheDiagram(): void
    {
        $this->login();
        $draft = $this->createPublishableDraft();

        $response = (new PublishVersionController())($draft->getID(), $this->jsonRequest());

        $this->assertSame(['published' => true], $this->decode($response));
        $this->assertSame(VersionStatus::PUBLISHED, $this->reloadItem($draft)->getStatus());
    }

    public function testDiagramTabOffersTheEditorAndVersionActionsPerRight(): void
    {
        $this->login();
        $draft    = $this->createPublishableDraft();
        $workflow = $draft->getItem();
        $this->assertInstanceOf(Workflow::class, $workflow);

        $config = $this->tabConfig($workflow);
        $this->assertSame('editor', $config['mode']);
        $this->assertIsArray($config['actions']);
        $this->assertSame(['publish', 'discard'], array_keys($config['actions']));
        $this->assertIsArray($config['pickers']);
        foreach ($config['pickers'] as $kind => $picker) {
            $this->assertIsArray($picker);
            $this->assertIsArray($picker['params']);
            $params = $picker['params'] + ($kind === 'user' ? ['itemtype' => User::class] : []);
            $this->assertTrue(Session::validateIDOR($params), sprintf('GLPI accepts the %s picker', $kind));
        }

        $this->assertSame([], $this->publish($draft));
        $config = $this->tabConfig($this->reloadItem($workflow));
        $this->assertSame('viewer', $config['mode']);
        $this->assertSame(['edit'], array_keys((array) $config['actions']));

        $_SESSION['glpiactiveprofile'][Workflow::$rightname] = READ;
        $this->assertSame([], $this->tabConfig($this->reloadItem($workflow))['actions']);
    }

    /**
     * Every string of the plugin is translated in pt_BR (spec 002, AC-10).
     */
    public function testEveryStringIsTranslatedInBrazilianPortuguese(): void
    {
        $catalog = (string) file_get_contents(__DIR__ . '/../locales/pt_BR.po');
        $template = (string) file_get_contents(__DIR__ . '/../locales/flowtask.pot');

        $this->assertSame([], $this->untranslated($catalog));
        $this->assertSame(
            $this->msgids($template),
            $this->msgids($catalog),
            'pt_BR.po follows flowtask.pot',
        );
    }

    /**
     * @return array{id: int, problems: list<string>}
     */
    private function addFromPalette(int $versions_id, NodeType $type): array
    {
        $data = $this->decode($this->controller()->addNode($versions_id, $this->json(['type' => $type->value, 'x' => 40, 'y' => 40])));
        $this->assertIsArray($data['node']);

        /** @var array{id: int, problems: list<string>} $node */
        $node = $data['node'];

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function tabConfig(Workflow $workflow): array
    {
        ob_start();
        Diagram::displayTabContentForItem($workflow);
        $html = (string) ob_get_clean();

        $this->assertSame(1, preg_match('/data-config="([^"]+)"/', $html, $matches));
        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($config);

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @return list<string>
     */
    private function untranslated(string $catalog): array
    {
        preg_match_all('/^msgid "(.+)"\nmsgstr ""\n\n/m', $catalog . "\n", $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function msgids(string $catalog): array
    {
        preg_match_all('/^(?:msgctxt "(.*)"\n)?msgid "(.+)"$/m', $catalog, $matches, PREG_SET_ORDER);
        $ids = array_map(static fn(array $match): string => $match[1] . '|' . $match[2], $matches);
        sort($ids);

        return $ids;
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

    private function jsonRequest(): Request
    {
        return Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $content = $response->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
