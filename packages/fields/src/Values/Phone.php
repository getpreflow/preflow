<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

use Preflow\Validation\Rules\Phone as PhoneRule;

/**
 * The value of a phone field: the number as the editor typed it and its normalized form (E.164:
 * "+" and the digits with the country code, no separators), for a `tel:` link and for matching.
 *
 * Stored as an object with both keys (toStored()):
 *
 *     {"as_typed": "044 123 45 67", "e164": "+41441234567"}
 *
 * The normalization is small and has no library (e164()):
 *
 * 1. a number with a leading "+" keeps its digits ("+41 44 123 45 67" gives "+41441234567"); a
 *    "(0)" directly after the country code is the national trunk 0 written for people and is
 *    dropped ("+41 (0)44 123 45 67" gives "+41441234567"); a bare 0 elsewhere stays (Italy: "+39 06 …");
 * 2. a leading "00" is the same as "+" ("0041 44 …" gives "+4144 …"). A "+" or "00" number has at
 *    least 7 digits after it (service numbers are written without "+");
 * 3. any other number is national: it gets the calling code of the field's default region
 *    (REGIONS, below), after dropping the one leading "0" that regions with a trunk prefix write
 *    ("044 123 45 67" with CH gives "+41441234567"; Italy keeps its 0). A US or Canadian number of
 *    11 digits may start with 1;
 * 4. a short national number (up to 5 digits, such as 112 or 1414) is a service number, not a
 *    subscriber: it has no E.164 form (e164 is null) and is dialled as typed;
 * 5. a longer national number without a default region, with a region this table lacks, or
 *    longer than 15 digits in all, cannot be normalized: e164() is null and problem() says why.
 *    The check of the field (ValidPhone) refuses it, naming what to do: start with "+" and the
 *    country code. A region this table lacks is no problem for a site that types "+" numbers.
 *
 * The number is judged as Preflow\Validation\Rules\Phone judges it (3 to 15 digits, the usual
 * separators). The table covers the regions below; for any other country, numbers are typed with "+".
 *
 * @since 0.91.0
 */
final readonly class Phone
{
    /**
     * The regions a national number can be completed for: region => [calling code, drops one
     * leading "0"]. Add none to a site's data: a number of another country is typed with "+".
     */
    public const array REGIONS = [
        'CH' => ['41', true], 'LI' => ['423', false], 'DE' => ['49', true], 'AT' => ['43', true],
        'FR' => ['33', true], 'IT' => ['39', false], 'ES' => ['34', false], 'PT' => ['351', false],
        'NL' => ['31', true], 'BE' => ['32', true], 'LU' => ['352', false], 'GB' => ['44', true],
        'IE' => ['353', true], 'DK' => ['45', false], 'SE' => ['46', true], 'NO' => ['47', false],
        'FI' => ['358', true], 'PL' => ['48', false], 'CZ' => ['420', false],
        'US' => ['1', false], 'CA' => ['1', false],
    ];

    /** A national number with up to this many digits is a service number (no E.164 form). */
    private const int SERVICE_DIGITS = 5;

    /** A number written with "+" or "00" has at least this many digits after it. */
    private const int INTERNATIONAL_DIGITS = 7;

    /** The separators of PhoneRule: space, no-break spaces, - . / */
    private const string SEPARATORS = ' \x{00A0}\x{202F}.\/-';

    public function __construct(
        public string $asTyped,
        public ?string $e164 = null,
    ) {
        if (trim($asTyped) === '') {
            throw new \InvalidArgumentException('A phone number needs the number as typed; use null for no number.');
        }
    }

    /** The number as typed with its E.164 form for the region (see e164()). */
    public static function of(string $typed, ?string $region = null): self
    {
        return new self(trim($typed), self::e164($typed, $region));
    }

    /**
     * The number a stored value holds: the object toStored() writes, JSON text of it (SQL storage),
     * or the number as plain text (its E.164 form only when it has a "+" or "00" start). Null for
     * an empty value or one that is no number; reading never fails.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (is_string($stored) && !str_starts_with(ltrim($stored), '{')) {
            return trim($stored) === '' ? null : self::of($stored);
        }
        $data = Link::decode($stored);
        if ($data === null || !is_string($data['as_typed'] ?? null) || trim($data['as_typed']) === '') {
            return null;
        }

        return new self(trim($data['as_typed']), is_string($data['e164'] ?? null) && $data['e164'] !== '' ? $data['e164'] : null);
    }

    /** @return array{as_typed: string, e164: string|null} */
    public function toStored(): array
    {
        return ['as_typed' => $this->asTyped, 'e164' => $this->e164];
    }

    /** "tel:+41441234567": the E.164 form, else the digits as typed; null when it is no phone number. */
    public function href(): ?string
    {
        $dialable = $this->e164 ?? PhoneRule::dialable($this->asTyped);

        return $dialable === null ? null : 'tel:' . $dialable;
    }

    /** The E.164 form of $typed for $region (a code of REGIONS, any case); null when there is none (see the class). */
    public static function e164(string $typed, ?string $region = null): ?string
    {
        return self::read($typed, $region)[0];
    }

    /**
     * Why $typed cannot be used: "validation.phone" when it is no phone number (or too long once the
     * country code is added), "validation.phone_region" when it needs a "+" and a country code, a
     * default region or one of REGIONS; null when it is fine (also when it has no E.164 form
     * because it is a service number).
     */
    public static function problem(string $typed, ?string $region = null): ?string
    {
        return self::read($typed, $region)[1];
    }

    /** "+41 (0)44 …" as "+41 44 …": the "(0)" that directly follows the country code of an international number. */
    private static function withoutTrunkZero(string $typed): string
    {
        $sep = '[' . self::SEPARATORS . ']*';
        $dropped = preg_replace('~^(\s*(?:\+|00)' . $sep . '\d(?:' . $sep . '\d){0,2}' . $sep . ')\(0\)~u', '$1', $typed);

        return is_string($dropped) ? $dropped : $typed;
    }

    /**
     * @return array{0: string|null, 1: string|null} [E.164 form, problem message key]
     */
    private static function read(string $typed, ?string $region): array
    {
        $dialable = PhoneRule::dialable(self::withoutTrunkZero($typed));
        if ($dialable === null) {
            return [null, 'validation.phone'];
        }
        if (str_starts_with($dialable, '+') || str_starts_with($dialable, '00')) {
            $digits = ltrim($dialable, '+');
            $digits = str_starts_with($dialable, '+') ? $digits : substr($digits, 2);

            return strlen($digits) >= self::INTERNATIONAL_DIGITS && strlen($digits) <= 15 ? ['+' . $digits, null] : [null, 'validation.phone'];
        }
        if (strlen($dialable) <= self::SERVICE_DIGITS) {
            return [null, null];
        }
        $entry = $region !== null ? (self::REGIONS[strtoupper($region)] ?? null) : null;
        if ($entry === null) {
            return [null, 'validation.phone_region'];
        }
        [$code, $dropsZero] = $entry;
        $national = $dialable;
        if ($dropsZero && str_starts_with($national, '0')) {
            $national = substr($national, 1);
        } elseif ($code === '1' && strlen($national) === 11 && str_starts_with($national, '1')) {
            $national = substr($national, 1);
        }
        if (strlen($code . $national) > 15) {
            return [null, 'validation.phone'];
        }

        return ['+' . $code . $national, null];
    }
}
