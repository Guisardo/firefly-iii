<?php

/*
 * UsesSharedAdministrationContext.php
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

namespace FireflyIII\Support\Http\Controllers;

use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\AbstractPaginator;

trait UsesSharedAdministrationContext
{
    protected function resolvedUserGroup(): ?UserGroup
    {
        $context = request()->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE);
        if ($context instanceof AdministrationContext && $context->hasResolvedAdministration()) {
            return $context->userGroup();
        }

        if (!app()->bound(AdministrationContext::class)) {
            return null;
        }

        $context = app(AdministrationContext::class);
        if ($context instanceof AdministrationContext && $context->hasResolvedAdministration()) {
            return $context->userGroup();
        }

        return null;
    }

    protected function applyResolvedUserGroup(object $target): void
    {
        $userGroup = $this->resolvedUserGroup();
        if (null !== $userGroup && method_exists($target, 'setUserGroup')) {
            $target->setUserGroup($userGroup);
        }
    }

    protected function appendResolvedUserGroupQuery(Paginator|AbstractPaginator $paginator): void
    {
        $userGroup = $this->resolvedUserGroup();
        if (null !== $userGroup && method_exists($paginator, 'appends')) {
            $paginator->appends(['user_group_id' => $userGroup->id]);
        }
    }
}
