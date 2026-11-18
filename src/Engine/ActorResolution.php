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

namespace GlpiPlugin\Flowtask\Engine;

use Group;
use User;

/**
 * Outcome of resolving the actor of a step (spec 001, engine rules).
 */
final readonly class ActorResolution
{
    /**
     * @param class-string<User|Group>|null $itemtype
     */
    private function __construct(
        public ?string $itemtype,
        public int $items_id,
        public bool $manager_missing,
        public string $failure,
    ) {}

    public static function user(int $users_id): self
    {
        return new self(User::class, $users_id, false, '');
    }

    public static function group(int $groups_id): self
    {
        return new self(Group::class, $groups_id, false, '');
    }

    /**
     * The requester manager could not be found: the step config decides
     * between blocking and skipping (AC-7).
     */
    public static function managerMissing(string $reason): self
    {
        return new self(null, 0, true, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(null, 0, false, $reason);
    }

    public function isResolved(): bool
    {
        return $this->itemtype !== null;
    }

    public function getUserId(): int
    {
        return $this->itemtype === User::class ? $this->items_id : 0;
    }

    public function getGroupId(): int
    {
        return $this->itemtype === Group::class ? $this->items_id : 0;
    }
}
