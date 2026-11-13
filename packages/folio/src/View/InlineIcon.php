<?php

declare(strict_types=1);

namespace Preflow\Folio\View;

/**
 * An icon of Folio's sprite (assets/icons.svg) as an inline <svg>, for markup that PHP writes
 * without the template's folio_asset() (field editors). The drawing is the sprite's symbol, so the
 * sprite stays the one source; the icon is decorative (aria-hidden): its control carries the name.
 *
 * @since 0.75
 */
final class InlineIcon
{
    /** @var array<string, array{string, string}>|null id => [attributes, inner markup] */
    private static ?array $symbols = null;

    public static function svg(string $id, string $class = 'folio-icon'): string
    {
        $symbol = self::symbols()[$id] ?? null;
        if ($symbol === null) {
            throw new \InvalidArgumentException("No icon \"{$id}\" in icons.svg");
        }

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' . $symbol[0] . ' aria-hidden="true" focusable="false">' . $symbol[1] . '</svg>';
    }

    /** @return array<string, array{string, string}> */
    private static function symbols(): array
    {
        if (self::$symbols === null) {
            self::$symbols = [];
            $sprite = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/icons.svg');
            preg_match_all('~<symbol id="([^"]+)"([^>]*)>(.*?)</symbol>~s', $sprite, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $id, $attributes, $inner]) {
                self::$symbols[$id] = [$attributes, $inner];
            }
        }

        return self::$symbols;
    }
}
