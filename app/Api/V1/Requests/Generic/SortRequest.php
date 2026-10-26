<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Generic;

use FireflyIII\Api\V1\Requests\ApiRequest;
use FireflyIII\Rules\IsValidSortInstruction;
use Illuminate\Contracts\Validation\Validator;
use Override;
use RuntimeException;

/**
 * The sort parameter of a list endpoint: comma separated fields, a leading minus means descending.
 * Allowed fields come from firefly.allowed_sort_parameters.<model>. The parsed instructions are put
 * in the request attributes as pairs of field and direction.
 */
class SortRequest extends ApiRequest
{
    private ?string $sortClass = null;

    #[Override]
    public function handleConfig(array $config): void
    {
        parent::handleConfig($config);

        $this->sortClass = $config['sort_class'] ?? null;

        if (null === $this->sortClass) {
            throw new RuntimeException('SortRequest requires a sort_class config');
        }
    }

    public function rules(): array
    {
        return [
            'sort' => ['nullable', new IsValidSortInstruction((string) $this->sortClass)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (count($validator->failed()) > 0) {
                return;
            }
            $this->attributes->set('sort', null !== $this->sortClass ? $this->convertSortParameters('sort', $this->sortClass) : []);
        });
    }
}
