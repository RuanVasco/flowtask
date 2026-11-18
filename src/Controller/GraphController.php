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
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\View\GraphBuilder;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;

/**
 * Graph read by the diagram component (spec 002): the graph of a version,
 * or the graph an instance runs on with its progress.
 */
final class GraphController extends AbstractController
{
    #[Route(
        path: '/WorkflowVersion/{id}/Graph',
        name: 'workflowversion_graph',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function version(int $id): JsonResponse
    {
        $version = new WorkflowVersion();
        if (!$version->getFromDB($id)) {
            throw new NotFoundHttpException();
        }

        $workflow = $version->getItem();
        if (!$workflow instanceof Workflow || !$workflow->can($workflow->getID(), READ)) {
            throw new AccessDeniedHttpException();
        }

        // The editor's data only goes to who may edit the draft.
        $editable = $version->isDraft() && $workflow->can($workflow->getID(), UPDATE);

        return new JsonResponse($this->builder()->build($version, editable: $editable));
    }

    /**
     * Readable by everyone who can view the ticket, like its "Workflow" tab.
     */
    #[Route(
        path: '/Instance/{id}/Graph',
        name: 'instance_graph',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function instance(int $id): JsonResponse
    {
        $instance = new Instance();
        $ticket   = new Ticket();
        $version  = new WorkflowVersion();
        if (
            !$instance->getFromDB($id)
            || !$ticket->getFromDB(Fields::int($instance->fields, Instance::$items_id))
            || !$version->getFromDB(Fields::int($instance->fields, WorkflowVersion::getForeignKeyField()))
        ) {
            throw new NotFoundHttpException();
        }

        if (!$ticket->can($ticket->getID(), READ)) {
            throw new AccessDeniedHttpException();
        }

        return new JsonResponse($this->builder()->build($version, $instance));
    }

    private function builder(): GraphBuilder
    {
        /** @var DBmysql $DB */
        global $DB;

        return new GraphBuilder($DB);
    }
}
