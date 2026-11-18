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

namespace GlpiPlugin\Flowtask\Http;

/**
 * Runs an action and collects the messages it queued for the next page (the
 * item classes report their rules through Session::addMessageAfterRedirect()),
 * so a JSON endpoint can answer with them instead. Messages queued before,
 * e.g. by another tab, stay in the session for the page they belong to.
 *
 * @template-covariant T
 */
final readonly class SessionMessages
{
    /**
     * @param T            $result
     * @param list<string> $errors   Plain text
     * @param list<string> $warnings Plain text
     */
    private function __construct(
        public mixed $result,
        public array $errors,
        public array $warnings,
    ) {}

    /**
     * @template R
     *
     * @param callable(): R $action
     *
     * @return self<R>
     */
    public static function collect(callable $action): self
    {
        $queued = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        try {
            $result = $action();
            $own    = $_SESSION['MESSAGE_AFTER_REDIRECT'];
        } finally {
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = $queued;
        }

        return new self($result, self::texts($own, ERROR), self::texts($own, WARNING));
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $messages, int $type): array
    {
        $texts = [];
        $typed = is_array($messages) ? ($messages[$type] ?? []) : [];
        foreach (is_array($typed) ? $typed : [] as $message) {
            if (is_string($message)) {
                // Queued messages are HTML; the diagram shows text.
                $texts[] = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5);
            }
        }

        return $texts;
    }
}
