<?php

/*
 * ResolvesAdministrationViews.php
 * Copyright (c) 2026 james@firefly-iii.org.
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
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\UserGroup;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;

trait ResolvesAdministrationViews
{
    /**
     * The shared administration UI is v2-only while the rest of the web app can still use v1.
     */
    private function administrationView(string $view, array $data = []): Factory|ViewContract
    {
        Config::set('view.layout', 'v2');
        View::getFinder()->setPaths([
            realpath(base_path('resources/views/v2')),
            realpath(base_path('resources/views')),
        ]);

        return view($view, $data);
    }
}
