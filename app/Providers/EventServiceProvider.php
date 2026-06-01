<?php

/**
 * EventServiceProvider.php
 * Copyright (c) 2019 james@firefly-iii.org
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

namespace FireflyIII\Providers;

use FireflyIII\Events\Model\UserGroup\SharedAdministrationAccessDenied;
use FireflyIII\Events\Model\UserGroup\SharedAdministrationGroupSelected;
use FireflyIII\Listeners\Model\UserGroup\LogsSharedAdministrationAccess;
use FireflyIII\Listeners\Model\UserGroup\LogsSharedAdministrationDenial;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Override;

/**
 * Class EventServiceProvider.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SharedAdministrationAccessDenied::class   => [
            LogsSharedAdministrationDenial::class,
        ],
        SharedAdministrationGroupSelected::class  => [
            LogsSharedAdministrationAccess::class,
        ],
        // is a Transaction Journal related event.
        // StoredTransactionGroup::class          => ['FireflyIII\Handlers\Events\StoredGroupEventHandler@runAllHandlers'],
        // TriggeredStoredTransactionGroup::class => ['FireflyIII\Handlers\Events\StoredGroupEventHandler@triggerRulesManually'],
        // is a Transaction Journal related event.
        //            UpdatedTransactionGroup::class         => ['FireflyIII\Handlers\Events\UpdatedGroupEventHandler@runAllHandlers'],
        //            DestroyedTransactionGroup::class       => ['FireflyIII\Handlers\Events\DestroyedGroupEventHandler@runAllHandlers'],
        // API related events:
        //            AccessTokenCreated::class              => ['FireflyIII\Handlers\Events\APIEventHandler@accessTokenCreated'],
        // account related events:
        //            StoredAccount::class                   => ['FireflyIII\Handlers\Events\StoredAccountEventHandler@recalculateCredit'],
        //            UpdatedAccount::class                  => ['FireflyIII\Handlers\Events\UpdatedAccountEventHandler@recalculateCredit'],
        // preferences
        //            UserGroupChangedPrimaryCurrency::class => ['FireflyIII\Handlers\Events\PreferencesEventHandler@resetPrimaryCurrencyAmounts'],
    ];

    /**
     * Register any events for your application.
     */
    #[Override]
    public function boot(): void {}
}
