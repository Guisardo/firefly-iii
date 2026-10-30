<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Data;

use Carbon\Carbon;
use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Data\DeletedRequest;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Transformers\DeletedItemTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

/**
 * Class DeletedController
 *
 * Lists what was deleted since a moment, so a client that keeps a copy of the data can remove it there too.
 * Only soft deleted rows can be listed: what was purged for good is gone.
 */
final class DeletedController extends Controller
{
    public const string RESOURCE_KEY = 'deleted-items';

    protected array $acceptedRoles = [UserRoleEnum::READ_ONLY];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function (Request $request, $next) {
            $this->validateUserGroup($request);

            return $next($request);
        });
    }

    /**
     * Oldest deletion first, ties by id, so a client can follow the list with a cursor.
     */
    public function index(DeletedRequest $request): JsonResponse
    {
        $pageSize = (int) $this->parameters->get('limit');
        $page     = (int) $this->parameters->get('page');
        $since    = $request->attributes->get('since');
        $types    = $request->attributes->get('types');
        $types    = is_array($types) ? $types : DeletedRequest::TYPES;

        $items = [];
        $total = 0;
        if (in_array('transactions', $types, true)) {
            $query = TransactionGroup::onlyTrashed()->where('transaction_groups.user_group_id', $this->userGroup->id);
            if ($since instanceof Carbon) {
                $query->where('transaction_groups.deleted_at', '>=', $since->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
            }
            $total = (clone $query)->count();
            $rows  = $query
                ->orderBy('transaction_groups.deleted_at')
                ->orderBy('transaction_groups.id')
                ->forPage($page, $pageSize)
                ->get(['transaction_groups.id', 'transaction_groups.deleted_at'])
            ;
            foreach ($rows as $row) {
                $items[] = [
                    'id'         => (int) $row->id,
                    'entity'     => 'transactions',
                    'deleted_at' => $row->deleted_at->toAtomString(),
                ];
            }
        }

        // the paging links carry the text the client sent.
        $sinceText = $request->query->get('since');
        if ($since instanceof Carbon && is_string($sinceText)) {
            $this->parameters->set('since', $sinceText);
        }
        $typesText = $request->query->get('types');
        if (is_string($typesText) && '' !== $typesText) {
            $this->parameters->set('types', $typesText);
        }

        $paginator = new LengthAwarePaginator($items, $total, $pageSize, $page);
        $paginator->setPath(route('api.v1.data.deleted').$this->buildParams());

        $resource = new FractalCollection($items, new DeletedItemTransformer(), self::RESOURCE_KEY);
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        return response()->json($this->getManager()->createData($resource)->toArray())->header('Content-Type', self::CONTENT_TYPE);
    }
}
