<?php

declare(strict_types=1);

namespace FireflyIII\Support\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ResolvesUserGroupParameter
{
    private const string PARAMETER = 'user_group_id';

    public static function hasExplicitUserGroup(Request $request): bool
    {
        return [] !== self::explicitValues($request);
    }

    public static function resolveExplicit(Request $request): ?int
    {
        $values = self::explicitValues($request);
        if ([] === $values) {
            return null;
        }

        return self::normalizeUnique($values);
    }

    public static function resolve(Request $request, ?int $fallback = null): int
    {
        return self::resolveExplicit($request) ?? (int) $fallback;
    }

    private static function explicitValues(Request $request): array
    {
        $values = [];

        if ($request->query->has(self::PARAMETER)) {
            $values[] = $request->query->all()[self::PARAMETER] ?? null;
        }

        if ($request->request->has(self::PARAMETER)) {
            $values[] = $request->request->all()[self::PARAMETER] ?? null;
        }

        if ($request->isJson() && $request->json()->has(self::PARAMETER)) {
            $values[] = $request->json()->all()[self::PARAMETER] ?? null;
        }

        return $values;
    }

    private static function normalizeUnique(array $values): int
    {
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

        if (strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            self::throwInvalid();
        }

        return (int) $value;
    }

    private static function throwInvalid(): never
    {
        $validator = Validator::make([], []);
        $validator->errors()->add(self::PARAMETER, 'The user group id field must be a positive integer.');

        throw new ValidationException($validator);
    }
}
