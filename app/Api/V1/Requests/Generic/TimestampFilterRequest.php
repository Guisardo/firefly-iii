<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Generic;

use Carbon\Carbon;
use FireflyIII\Api\V1\Requests\ApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Throwable;

/**
 * Created/updated "since" and "until" filters for list endpoints.
 *
 * A value is ISO-8601: a date (2026-06-15) or a date and time (2026-06-15T10:00:00+02:00, 2026-06-15T08:00:00Z).
 * A date without a time is the start of that day for "since" and the end of that day for "until".
 * A time without an offset is read in the application timezone. Bounds are inclusive.
 * Moments are converted to the application timezone, the one the timestamps are stored in.
 * The parsed moments are put in the request attributes, under the name of the parameter.
 */
class TimestampFilterRequest extends ApiRequest
{
    private const array FIELDS  = ['created_since', 'created_until', 'updated_since', 'updated_until'];
    public const string FORMAT  = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    public function rules(): array
    {
        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'regex:'.self::FORMAT];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (count($validator->failed()) > 0) {
                return;
            }
            $moments = [];
            foreach (self::FIELDS as $field) {
                // read the text from the query: the attribute of the same name holds the parsed moment.
                $raw = $this->query->get($field);
                if (!is_string($raw) || '' === $raw) {
                    continue;
                }
                $moment = self::parseMoment($raw, str_ends_with($field, '_until'));
                if (!$moment instanceof Carbon) {
                    $validator->errors()->add($field, sprintf('The %s field must be a valid ISO-8601 date or date and time.', $field));

                    continue;
                }
                $moments[$field] = $moment;
            }
            foreach (['created', 'updated'] as $kind) {
                $since = $moments[$kind.'_since'] ?? null;
                $until = $moments[$kind.'_until'] ?? null;
                if ($since instanceof Carbon && $until instanceof Carbon && $until->lt($since)) {
                    $validator->errors()->add($kind.'_until', sprintf('The %s_until field must not be before %s_since.', $kind, $kind));
                }
            }
            if ($validator->errors()->count() > 0) {
                return;
            }
            foreach ($moments as $field => $moment) {
                $this->attributes->set($field, $moment);
            }
        });
    }

    public static function parseMoment(string $raw, bool $endOfPeriod): ?Carbon
    {
        $timezone = (string) config('app.timezone');

        try {
            if (10 === strlen($raw)) {
                $date = Carbon::createFromFormat('!Y-m-d', $raw, $timezone);
                if (!$date instanceof Carbon || $date->format('Y-m-d') !== $raw) {
                    return null;
                }

                return $endOfPeriod ? $date->endOfDay() : $date->startOfDay();
            }

            return Carbon::parse($raw, $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
