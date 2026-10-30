<?php

declare(strict_types=1);

namespace FireflyIII\Transformers;

/**
 * Class DeletedItemTransformer
 */
class DeletedItemTransformer extends AbstractTransformer
{
    /**
     * An entry of the list of deleted items: which one, what kind of thing it was, and when it was deleted.
     */
    public function transform(array $item): array
    {
        return [
            'id'         => $item['id'],
            'entity'     => $item['entity'],
            'deleted_at' => $item['deleted_at'],
        ];
    }
}
