<?php

/*
 * AdministrationContext.php
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Support\Http\SharedAdministration;

use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use LogicException;

class AdministrationContext
{
    public const string REQUEST_ATTRIBUTE = 'firefly_shared_administration';
    public const string SOURCE_EXPLICIT = 'explicit';
    public const string SOURCE_ROUTE = 'route';
    public const string SOURCE_SELECTED_DEFAULT = 'selected_default';

    private ?User $user                = null;
    private ?UserGroup $userGroup      = null;
    private array $acceptedRoles       = [];
    private ?string $source            = null;

    public function clear(): void
    {
        $this->user                = null;
        $this->userGroup           = null;
        $this->acceptedRoles       = [];
        $this->source              = null;
    }

    public function set(User $user, UserGroup $userGroup, array $acceptedRoles, string $source = self::SOURCE_EXPLICIT): void
    {
        $this->user                = $user;
        $this->userGroup           = $userGroup;
        $this->acceptedRoles       = $acceptedRoles;
        $this->source              = $source;
    }

    public function hasResolvedAdministration(): bool
    {
        return null !== $this->userGroup;
    }

    public function hasExplicitUserGroupId(): bool
    {
        return in_array($this->source, [self::SOURCE_EXPLICIT, self::SOURCE_ROUTE], true);
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function user(): User
    {
        if (null === $this->user) {
            throw new LogicException('Shared administration user has not been resolved.');
        }

        return $this->user;
    }

    public function userGroup(): UserGroup
    {
        if (null === $this->userGroup) {
            throw new LogicException('Shared administration user group has not been resolved.');
        }

        return $this->userGroup;
    }

    public function acceptedRoles(): array
    {
        return $this->acceptedRoles;
    }
}
