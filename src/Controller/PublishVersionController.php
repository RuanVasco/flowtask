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
use GlpiPlugin\Flowtask\Definition\GraphValidator;
use GlpiPlugin\Flowtask\Definition\VersionPublisher;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Html;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishes a draft version (spec 001, AC-1), from the version card or from
 * the diagram, which asks for JSON to point at the steps with a problem
 * (spec 002, AC-6).
 */
final class PublishVersionController extends AbstractController
{
    #[Route(
        path: '/WorkflowVersion/{id}/Publish',
        name: 'workflowversion_publish',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function __invoke(int $id, Request $request): Response
    {
        /** @var DBmysql $DB */
        global $DB;

        $version = new WorkflowVersion();
        if (!$version->getFromDB($id)) {
            throw new NotFoundHttpException();
        }

        // Rights are checked on the workflow: a version that is no longer a
        // draft (e.g. a second click) is not updatable, but deserves a message.
        $workflow = $version->getItem();
        if (!$workflow instanceof Workflow || !$workflow->can($workflow->getID(), UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        $errors = (new VersionPublisher($DB))->publish($version, (int) Session::getLoginUserID());

        if (in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            if ($errors === []) {
                // Shown once the diagram reloads the page.
                Session::addMessageAfterRedirect(__s('The version has been published.', 'flowtask'));

                return new JsonResponse(['published' => true]);
            }

            return new JsonResponse([
                'errors'   => $errors,
                'problems' => (new GraphValidator($DB))->findVersionProblems($version->getID()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($errors === []) {
            Session::addMessageAfterRedirect(__s('The version has been published.', 'flowtask'));
        } else {
            Session::addMessageAfterRedirect(
                __s('The version cannot be published:', 'flowtask')
                    . '<ul><li>' . implode('</li><li>', array_map(htmlescape(...), $errors)) . '</li></ul>',
                false,
                ERROR,
            );
        }

        // Back to the workflow or the version page the button was on.
        return new RedirectResponse(Html::getBackUrl() ?: $version->getLinkURL());
    }
}
