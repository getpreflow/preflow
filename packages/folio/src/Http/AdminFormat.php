<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Folio\FolioText;
use Preflow\I18n\Translator;

/**
 * Dates and numbers in the interface language (Twig: folio_date(), folio_number()). The
 * patterns are ICU patterns from Folio's language files (folio.format.date / .datetime), so a
 * site changes them in its lang/{locale}/folio.php. With ext-intl, IntlDateFormatter and
 * NumberFormatter format them in the interface locale; without it, the tokens Folio's
 * patterns use (d, dd, MM, MMM, yyyy, HH, mm) are mapped to date() and numbers take the
 * language's separators (folio.format.decimal / .thousands). Times are shown in the app's
 * timezone (app.timezone, PHP's default zone).
 */
final class AdminFormat
{
    private readonly bool $intl;

    /** @param bool|null $intl null: use ext-intl when it is loaded */
    public function __construct(
        private readonly ?Translator $translator = null,
        ?bool $intl = null,
    ) {
        $this->intl = ($intl ?? true) && extension_loaded('intl');
    }

    /**
     * A date (ISO string, timestamp or DateTimeInterface) as the language writes it; '' for
     * nothing or something that is not a date.
     *
     * @param 'date'|'datetime' $style
     */
    public function date(mixed $value, string $style = 'date'): string
    {
        $date = self::toDate($value);
        if ($date === null) {
            return '';
        }
        $date = $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $pattern = $this->text($style === 'datetime' ? 'folio.format.datetime' : 'folio.format.date');

        if ($this->intl) {
            $formatter = new \IntlDateFormatter($this->locale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), null, $pattern);
            $text = $formatter->format($date);
            if (is_string($text)) {
                return $text;
            }
        }

        return $date->format(self::phpPattern($pattern));
    }

    /** A number with the language's separators ('' for something that is not a number). */
    public function number(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $number = $value + 0;
        if ($this->intl) {
            $text = (new \NumberFormatter($this->locale(), \NumberFormatter::DECIMAL))->format($number);
            if (is_string($text)) {
                return $text;
            }
        }
        $decimals = is_float($number) ? strlen(rtrim(substr(strrchr(sprintf('%.6F', $number), '.') ?: '', 1), '0')) : 0;

        return number_format((float) $number, $decimals, $this->text('folio.format.decimal'), $this->text('folio.format.thousands'));
    }

    private function locale(): string
    {
        return ($this->translator ?? FolioText::translator())->getLocale();
    }

    private function text(string $key): string
    {
        return ($this->translator ?? FolioText::translator())->get($key);
    }

    private static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return (new \DateTimeImmutable())->setTimestamp($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** The ICU tokens of Folio's patterns as date() characters; other letters are kept as text. */
    private static function phpPattern(string $icu): string
    {
        $map = ['yyyy' => 'Y', 'MMM' => 'M', 'MM' => 'm', 'dd' => 'd', 'd' => 'j', 'HH' => 'H', 'mm' => 'i'];
        $out = '';
        $i = 0;
        $length = strlen($icu);
        while ($i < $length) {
            foreach ($map as $token => $php) {
                if (substr($icu, $i, strlen($token)) === $token) {
                    $out .= $php;
                    $i += strlen($token);
                    continue 2;
                }
            }
            $char = $icu[$i++];
            $out .= ctype_alpha($char) ? '\\' . $char : $char;
        }

        return $out;
    }
}
