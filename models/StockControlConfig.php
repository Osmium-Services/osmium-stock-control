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

    /**
     * The settings file's new text: its PHP guard line and any other settings are kept
     *
     * @param string|null $existing The file's current text, or null when there is none
     */
    public static function toContent(?string $existing, ?int $inStockStatusId, ?int $outOfStockStatusId): string
    {
        $jsonStart = $existing === null ? false : \strpos(haystack: $existing, needle: '{');
        $header = $jsonStart === false ? "<?php exit(); ?>\n" : \substr(string: $existing, offset: 0, length: $jsonStart);
        $decoded = $jsonStart === false ? [] : \json_decode(\substr(string: $existing, offset: $jsonStart), associative: true);
        $data = \is_array($decoded) ? $decoded : [];

        $data['stockControl'] = ['inStockStatusId' => $inStockStatusId, 'outOfStockStatusId' => $outOfStockStatusId];

        $json = \json_encode(value: $data, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $header . $json . "\n";
    }

    /**
     * What is wrong with the chosen statuses, or null when they can be saved
     *
     * @param array{product_available: int}|null $inStock The chosen in-stock status row, null when none chosen
     * @param array{product_available: int}|null $outOfStock The chosen out-of-stock status row, null when none chosen
     */
    public static function problem(?array $inStock, ?array $outOfStock): ?string
    {
        $chosenNeither = $inStock === null && $outOfStock === null;
        if ($chosenNeither) return null;

        $missingOne = $inStock === null || $outOfStock === null;
        if ($missingOne) return 'Choose both statuses, or neither';

        $inStockUnavailable = (int)$inStock['product_available'] !== 1;
        if ($inStockUnavailable) return 'The in-stock status must be one that allows buying';

        $outOfStockAvailable = (int)$outOfStock['product_available'] === 1;
        if ($outOfStockAvailable) return 'The out-of-stock status must be one that does not allow buying';

        return null;
    }

    private static function statusId(mixed $value): ?int
    {
        $id = \is_numeric($value) ? (int)$value : 0;

        return $id > 0 ? $id : null;
    }
}
