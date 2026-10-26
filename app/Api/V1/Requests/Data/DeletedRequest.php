<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Data;

use Carbon\Carbon;
use FireflyIII\Api\V1\Requests\ApiRequest;
use FireflyIII\Api\V1\Requests\Generic\TimestampFilterRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Parameters of the list of deleted items.
 *
 * "since" is an ISO-8601 date or date and time (see TimestampFilterRequest), bounds are inclusive.
 * "types" is a comma separated list of the entities to look at, only the ones in TYPES are supported.
 * The parsed values are put in the request attributes under the name of the parameter,
 * the text the client sent stays in the query.
 */
class DeletedRequest extends ApiRequest
{
    public const array TYPES = ['transactions'];

    public function rules(): array
    {
        return [
            'since' => ['nullable', 'string', 'regex:'.TimestampFilterRequest::FORMAT],
            'types' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (count($validator->failed()) > 0) {
                return;
            }
            $since = null;
            $raw   = $this->query->get('since');
            if (is_string($raw) && '' !== $raw) {
                $since = TimestampFilterRequest::parseMoment($raw, false);
                if (!$since instanceof Carbon) {
                    $validator->errors()->add('since', 'The since field must be a valid ISO-8601 date or date and time.');
                }
            }
            $types = self::TYPES;
            $text  = $this->query->get('types');
            if (is_string($text) && '' !== trim($text)) {
                $types   = array_values(array_unique(array_filter(array_map('trim', explode(',', $text)), static fn (string $type): bool => '' !== $type)));
                $unknown = array_diff($types, self::TYPES);
                if (count($unknown) > 0) {
                    $validator->errors()->add('types', sprintf('The types field only supports: %s.', implode(', ', self::TYPES)));
                }
            }
            if ($validator->errors()->count() > 0) {
                return;
            }
            $this->attributes->set('since', $since);
            $this->attributes->set('types', $types);
        });
    }
}
