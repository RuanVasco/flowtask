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
use Glpi\Http\RedirectResponse;
use GlpiPlugin\Flowtask\Engine\WorkflowEngine;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\Support\Fields;
use Html;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;

/**
 * Retry and cancel buttons of the ticket "Workflow" tab (spec 001, ticket tab rules).
 */
final class InstanceActionController extends AbstractController
{
    #[Route(
        path: '/Instance/{id}/' . Instance::ACTION_RETRY,
        name: 'instance_retry',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function retry(int $id): Response
    {
        [$instance, $ticket] = $this->load($id);
        if (!Instance::canRetryOn($ticket)) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->engine()->retry($instance)) {
            $this->addNotApplicableMessage();

            return $this->back($ticket);
        }

        // A step still blocked has already said why.
        $message = match ($instance->getStatus()) {
            InstanceStatus::RUNNING   => __s('The workflow has been resumed.', 'flowtask'),
            InstanceStatus::COMPLETED => __s('The workflow has been completed.', 'flowtask'),
            default                   => null,
        };
        if ($message !== null) {
            Session::addMessageAfterRedirect($message);
        }

        return $this->back($ticket);
    }

    #[Route(
        path: '/Instance/{id}/' . Instance::ACTION_CANCEL,
        name: 'instance_cancel',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function cancel(int $id): Response
    {
        [$instance, $ticket] = $this->load($id);
        if (!Instance::canCancelOn($ticket)) {
            throw new AccessDeniedHttpException();
        }

        if ($this->engine()->cancel($instance)) {
            Session::addMessageAfterRedirect(__s('The workflow has been cancelled.', 'flowtask'));
        } else {
            $this->addNotApplicableMessage();
        }

        return $this->back($ticket);
    }

    /**
     * @return array{Instance, Ticket}
     */
    private function load(int $id): array
    {
        $instance = new Instance();
        $ticket   = new Ticket();
        if (!$instance->getFromDB($id) || !$ticket->getFromDB(Fields::int($instance->fields, Instance::$items_id))) {
            throw new NotFoundHttpException();
        }

        return [$instance, $ticket];
    }

    private function addNotApplicableMessage(): void
    {
        Session::addMessageAfterRedirect(
            __s('This action no longer applies to the workflow of this ticket.', 'flowtask'),
            false,
            WARNING,
        );
    }

    private function back(Ticket $ticket): Response
    {
        // getBackUrl() falls back to the home page, the ticket is a better one.
        return new RedirectResponse(Html::getRefererUrl() ?? $ticket->getLinkURL());
    }

    private function engine(): WorkflowEngine
    {
        /** @var DBmysql $DB */
        global $DB;

        return WorkflowEngine::fromDb($DB);
    }
}
