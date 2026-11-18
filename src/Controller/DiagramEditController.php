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

namespace GlpiPlugin\Flowtask\Controller;

use DBmysql;
use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Http\SessionMessages;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\View\GraphBuilder;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Changes made on the diagram editor (spec 002, persistence and rules). Every
 * change goes through the Node and Transition classes, so the rules of spec
 * 001 stay in one place; a refused change answers with their messages.
 *
 * GLPI checks POST requests against CSRF before they reach a controller.
 */
final class DiagramEditController extends AbstractController
{
    #[Route(
        path: '/WorkflowVersion/{id}/Node',
        name: 'workflowversion_add_node',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function addNode(int $id, Request $request): JsonResponse
    {
        $version = $this->loadDraft($id);
        if ($version instanceof JsonResponse) {
            return $version;
        }

        $body = $this->body($request);
        $type = NodeType::tryFrom(Fields::string($body, 'type'));
        $name = trim(Fields::string($body, 'name'));

        $node  = new Node();
        $added = SessionMessages::collect(static fn(): int|false => $node->add([
            Node::$items_id => $version->getID(),
            'node_type'     => Fields::string($body, 'type'),
            // A step added from the palette is named after its type.
            'name'          => $name !== '' ? $name : ($type?->label() ?? ''),
            'position_x'    => Fields::int($body, 'x'),
            'position_y'    => Fields::int($body, 'y'),
        ]));

        return $this->answer($added, $added->result !== false, fn(): array => ['node' => $this->builder()->node($node)]);
    }

    /**
     * Saves where steps were dropped, or where the automatic layout put them.
     */
    #[Route(
        path: '/WorkflowVersion/{id}/Positions',
        name: 'workflowversion_positions',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function savePositions(int $id, Request $request): JsonResponse
    {
        $version = $this->loadDraft($id);
        if ($version instanceof JsonResponse) {
            return $version;
        }

        $positions = $this->body($request)['positions'] ?? null;
        if (!is_array($positions)) {
            throw new BadRequestHttpException();
        }

        $saved = SessionMessages::collect(static function () use ($positions, $version): bool {
            $saved = true;
            foreach ($positions as $position) {
                $node = new Node();
                if (
                    !$node->getFromDB(Fields::int($position, 'id'))
                    || Fields::int($node->fields, Node::$items_id) !== $version->getID()
                ) {
                    continue;
                }

                $saved = $node->update([
                    'id'         => $node->getID(),
                    'position_x' => Fields::int($position, 'x'),
                    'position_y' => Fields::int($position, 'y'),
                ]) && $saved;
            }

            return $saved;
        });

        return $this->answer($saved, $saved->result, static fn(): array => []);
    }

    #[Route(
        path: '/WorkflowVersion/{id}/Transition',
        name: 'workflowversion_add_transition',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function addTransition(int $id, Request $request): JsonResponse
    {
        $version = $this->loadDraft($id);
        if ($version instanceof JsonResponse) {
            return $version;
        }

        $body       = $this->body($request);
        $transition = new Transition();
        $added      = SessionMessages::collect(static fn(): int|false => $transition->add([
            Transition::$items_id             => $version->getID(),
            'plugin_flowtask_nodes_id_source' => Fields::int($body, 'source'),
            'plugin_flowtask_nodes_id_target' => Fields::int($body, 'target'),
            'trigger_condition'               => Fields::string($body, 'condition'),
        ]));

        return $this->answer($added, $added->result !== false, fn(): array => ['edge' => $this->builder()->edge($transition->fields)]);
    }

    /**
     * Removes a step with its transitions (spec 002, AC-5).
     */
    #[Route(
        path: '/Node/{id}/Delete',
        name: 'node_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteNode(int $id): JsonResponse
    {
        $node = new Node();
        if (!$node->getFromDB($id)) {
            throw new NotFoundHttpException();
        }

        $version = $this->loadDraft(Fields::int($node->fields, Node::$items_id));
        if ($version instanceof JsonResponse) {
            return $version;
        }

        if ($node->isStart()) {
            return $this->refusedWith([__('The start step cannot be deleted.', 'flowtask')]);
        }

        $deleted = SessionMessages::collect(static fn(): bool => $node->delete(['id' => $id], true));

        return $this->answer($deleted, $deleted->result, static fn(): array => []);
    }

    #[Route(
        path: '/Transition/{id}/Delete',
        name: 'transition_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteTransition(int $id): JsonResponse
    {
        $transition = new Transition();
        if (!$transition->getFromDB($id)) {
            throw new NotFoundHttpException();
        }

        $version = $this->loadDraft(Fields::int($transition->fields, Transition::$items_id));
        if ($version instanceof JsonResponse) {
            return $version;
        }

        $deleted = SessionMessages::collect(static fn(): bool => $transition->delete(['id' => $id], true));

        return $this->answer($deleted, $deleted->result, static fn(): array => []);
    }

    /**
     * The version to change, once the user may update its workflow (rights
     * and entity) and it is still a draft.
     */
    private function loadDraft(int $id): WorkflowVersion|JsonResponse
    {
        $version = new WorkflowVersion();
        if (!$version->getFromDB($id)) {
            throw new NotFoundHttpException();
        }

        $workflow = $version->getItem();
        if (!$workflow instanceof Workflow || !$workflow->can($workflow->getID(), UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        if (!$version->isDraft()) {
            return $this->refusedWith(
                [__('Steps and transitions can only be changed in a draft version.', 'flowtask')],
                Response::HTTP_CONFLICT,
            );
        }

        return $version;
    }

    /**
     * @return array<mixed>
     */
    private function body(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            throw new BadRequestHttpException();
        }
    }

    /**
     * The data of a saved change with its warnings, or the reasons it was refused.
     *
     * @param SessionMessages<mixed>           $action
     * @param callable(): array<string, mixed> $data
     */
    private function answer(SessionMessages $action, bool $saved, callable $data): JsonResponse
    {
        if (!$saved) {
            return $this->refusedWith($action->errors !== [] ? $action->errors : [__('The change could not be saved.', 'flowtask')]);
        }

        return new JsonResponse($data() + ['warnings' => $action->warnings]);
    }

    /**
     * @param list<string> $errors
     */
    private function refusedWith(array $errors, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return new JsonResponse(['errors' => $errors], $status);
    }

    private function builder(): GraphBuilder
    {
        /** @var DBmysql $DB */
        global $DB;

        return new GraphBuilder($DB);
    }
}
