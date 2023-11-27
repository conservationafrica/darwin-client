<?php

declare(strict_types=1);

namespace Darwin\Test\Integration;

use function assert;
use function getenv;
use function PHPUnit\Framework\assertIsNumeric;
use function PHPUnit\Framework\assertIsString;

final class ConfigValues
{
    /** @return non-empty-string */
    public static function apiUrl(): string
    {
        $value = getenv('API_URL');
        assertIsString($value);
        assert($value !== '');

        return $value;
    }

    /** @return non-empty-string */
    public static function secret(): string
    {
        $value = getenv('API_SECRET');
        assertIsString($value);
        assert($value !== '');

        return $value;
    }

    /** @return non-empty-string */
    public static function basePath(): string
    {
        return '/AJAX/'; // Because it's still the early 2000's
    }

    /** @return positive-int */
    public static function companyId(): int
    {
        $id = getenv('COMPANY_ID');
        assertIsNumeric($id);
        $id = (int) $id;
        assert($id > 0);

        return $id;
    }
}
