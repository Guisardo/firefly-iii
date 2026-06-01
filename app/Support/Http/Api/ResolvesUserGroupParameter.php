<?php

declare(strict_types=1);

namespace FireflyIII\Support\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ResolvesUserGroupParameter
{
    public static function hasExplicitUserGroup(Request $request): bool
    {
        return $request->query->has('user_group_id') || $request->request->has('user_group_id');
    }

    public static function resolve(Request $request, ?int $fallback = null): int
    {
        $values = [];

        if ($request->query->has('user_group_id')) {
            $queryValue = $request->query->get('user_group_id');
            $values     = array_merge($values, is_array($queryValue) ? $queryValue : [$queryValue]);
        }

        if ($request->request->has('user_group_id')) {
            $bodyValue = $request->request->get('user_group_id');
            if (is_array($bodyValue)) {
                self::throwInvalid();
            }
            $values[]  = $bodyValue;
        }

        if ([] === $values) {
            return (int) $fallback;
        }

        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = self::normalize($value);
        }

        if (count(array_unique($normalized)) > 1) {
            throw new ConflictHttpException('Conflicting user_group_id values were supplied.');
        }

        return $normalized[0];
    }

    private static function normalize(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            self::throwInvalid();
        }

        $value = trim((string) $value);
        if (1 !== preg_match('/^[1-9][0-9]*$/', $value)) {
            self::throwInvalid();
        }

        if (strlen($value) > strlen((string) PHP_INT_MAX) || strcmp($value, (string) PHP_INT_MAX) > 0) {
            self::throwInvalid();
        }

        return (int) $value;
    }

    private static function throwInvalid(): never
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('user_group_id', 'The user group id field must be a positive integer.');

        throw new ValidationException($validator);
    }
}
