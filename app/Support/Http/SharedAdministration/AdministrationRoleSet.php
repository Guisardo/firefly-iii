<?php

/*
 * AdministrationRoleSet.php
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

use FireflyIII\Enums\UserRoleEnum;

class AdministrationRoleSet
{
    public static function allowedTitles(array $acceptedRoles): array
    {
        $allowed = [];
        foreach ($acceptedRoles as $role) {
            if (!$role instanceof UserRoleEnum) {
                continue;
            }

            $allowed[] = $role->value;
            if (UserRoleEnum::OWNER !== $role) {
                $allowed[] = UserRoleEnum::OWNER->value;
            }
            if (!in_array($role, [UserRoleEnum::FULL, UserRoleEnum::OWNER], true)) {
                $allowed[] = UserRoleEnum::FULL->value;
            }
        }

        return array_values(array_unique($allowed));
    }
}
