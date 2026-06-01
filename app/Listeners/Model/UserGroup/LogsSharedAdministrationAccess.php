<?php

declare(strict_types=1);

/*
 * LogsSharedAdministrationAccess.php
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

namespace FireflyIII\Listeners\Model\UserGroup;

use FireflyIII\Events\Model\UserGroup\SharedAdministrationGroupSelected;
use Illuminate\Support\Facades\Log;

class LogsSharedAdministrationAccess
{
    public function handle(SharedAdministrationGroupSelected $event): void
    {
        Log::channel('audit')->info('Shared administration group selected.', [
            'user_id'               => $event->user->id,
            'requested_user_group_id' => $event->userGroup->id,
            'default_user_group_id' => $event->defaultUserGroupId,
            'handler'               => $event->handler,
            'accepted_roles'        => $event->acceptedRoles,
        ]);
    }
}
