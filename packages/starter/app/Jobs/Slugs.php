<?php

declare(strict_types=1);

namespace App\Jobs;

use Preflow\Data\DataManager;

final class Slugs
{
    private const array LETTERS = [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ý' => 'Y',
    ];

    /**
     * A slug for $text that no other record of $class has: "senior-developer", then
     * "senior-developer-2", "-3"... $exceptUuid is the record being renamed, which may keep its own.
     *
     * @param class-string<\Preflow\Data\Model> $class
     */
    public static function unique(DataManager $data, string $class, string $text, ?string $exceptUuid = null): string
    {
        $base = self::slugify($text);

        $candidate = $base;
        for ($n = 2; ; $n++) {
            $found = $data->query($class)->where('slug', $candidate)->first();
            if ($found === null || ($exceptUuid !== null && $found->uuid === $exceptUuid)) {
                return $candidate;
            }
            $candidate = $base . '-' . $n;
        }
    }

    private static function slugify(string $text): string
    {
        // iconv's transliteration depends on the system's locale ("ü" can come out as "u" or as
        // "?"), so the common letters are mapped here first and iconv only handles the rest.
        $ascii = strtr($text, self::LETTERS);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
        $ascii = $ascii === false ? $text : $ascii;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $slug = rtrim(substr($slug, 0, 80), '-');

        return $slug === '' ? 'item' : $slug;
    }
}
