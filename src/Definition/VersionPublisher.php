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

namespace GlpiPlugin\Flowtask\Definition;

use DBmysql;
use GlpiPlugin\Flowtask\Enum\VersionStatus;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Session;
use Throwable;

/**
 * Publishes a draft version (spec 001, AC-1 and AC-2).
 */
final readonly class VersionPublisher
{
    private GraphValidator $validator;

    public function __construct(private DBmysql $db)
    {
        $this->validator = new GraphValidator($db);
    }

    /**
     * Publishes the draft and archives the version it replaces. Nothing
     * changes when the graph is invalid.
     *
     * @return list<string> Translated problems, empty on success
     */
    public function publish(WorkflowVersion $draft, int $users_id): array
    {
        if (!$draft->isDraft()) {
            return [__('Only a draft version can be published.', 'flowtask')];
        }

        $errors = $this->validator->validateVersion($draft->getID());
        if ($errors !== []) {
            return $errors;
        }

        // Archive and promote together: a failure must not leave the workflow
        // without a published version.
        $this->db->beginTransaction();
        try {
            if (!$this->archivePublished(Fields::int($draft->fields, WorkflowVersion::$items_id))
                || !$draft->changeLifecycle([
                    'status'             => VersionStatus::PUBLISHED->value,
                    'users_id_publisher' => $users_id,
                    'date_publication'   => Session::getCurrentTime(),
                ])
            ) {
                $this->db->rollBack();

                return [__('The version could not be published.', 'flowtask')];
            }

            $this->db->commit();
        } catch (Throwable $throwable) {
            $this->db->rollBack();

            throw $throwable;
        }

        return [];
    }

    private function archivePublished(int $workflows_id): bool
    {
        $published = $this->db->request([
            'SELECT' => 'id',
            'FROM'   => WorkflowVersion::getTable(),
            'WHERE'  => [
                WorkflowVersion::$items_id => $workflows_id,
                'status'                   => VersionStatus::PUBLISHED->value,
            ],
        ]);
        foreach ($published as $row) {
            $previous = new WorkflowVersion();
            if (
                !$previous->getFromDB(Fields::int($row, 'id'))
                || !$previous->changeLifecycle(['status' => VersionStatus::ARCHIVED->value])
            ) {
                return false;
            }
        }

        return true;
    }
}
