<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * This service's settings file (app/config/services/osmium-stock-control.json.php):
 * which product statuses mean in stock and out of stock. Until both are chosen,
 * stock is still counted but a product's status is never changed.
 */
class StockControlConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/osmium-stock-control.json.php';

    public static function get(): object
    {
        if (self::$config !== null) return self::$config;

        $content = \file_exists(self::$configPath) ? (string)\file_get_contents(self::$configPath) : null;
        self::$config = self::fromContent($content);

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /**
     * @param string|null $content The settings file's text (a PHP header, then JSON), or null when there is no file
     * @return object{outOfStockStatusId: ?int, inStockStatusId: ?int}
     */
    public static function fromContent(?string $content): object
    {
        $saved = [];

        $jsonStart = $content === null ? false : \strpos(haystack: $content, needle: '{');
        if ($jsonStart !== false) {
            $decoded = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true);
            $saved = \is_array($decoded) ? ($decoded['stockControl'] ?? []) : [];
        }

        return (object)[
            'outOfStockStatusId' => self::statusId($saved['outOfStockStatusId'] ?? null),
            'inStockStatusId' => self::statusId($saved['inStockStatusId'] ?? null),
        ];
    }

    private static function statusId(mixed $value): ?int
    {
        $id = \is_numeric($value) ? (int)$value : 0;

        return $id > 0 ? $id : null;
    }
}
