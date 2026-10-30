<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Models\Transaction;

use FireflyIII\Api\V1\Requests\AggregateFormRequest;
use FireflyIII\Api\V1\Requests\Generic\SortRequest;
use FireflyIII\Api\V1\Requests\Generic\TimestampFilterRequest;
use FireflyIII\Models\Transaction;
use Override;

/**
 * Parameters of the transaction list that come on top of what the controller reads itself
 * (limit, page, start, end and type keep their behaviour).
 */
class ShowRequest extends AggregateFormRequest
{
    #[Override]
    protected function getRequests(): array
    {
        return [
            TimestampFilterRequest::class,
            [SortRequest::class, 'sort_class' => Transaction::class],
        ];
    }
}
