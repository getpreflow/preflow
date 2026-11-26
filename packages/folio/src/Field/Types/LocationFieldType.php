<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\LocationPicker;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Location;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\FolioText;

/**
 * A place (0.91; the value, check and storage are preflow/fields': Values\Location, ValidLocation).
 * The editor has number inputs for latitude and longitude and a line for the address. A site that
 * binds a Preflow\Fields\LocationPicker gets its markup below them (a map, a place search) and its
 * files (editorAssets(), served by Folio's AdminAssets); the picker fills the inputs, which stay the
 * value. Without one, no map and no third-party request.
 *
 * Posted: {name}[lat], {name}[lng], {name}[address]. Stored: {lat, lng, address} with the numbers
 * as numbers (a decimal comma is read as a point), or null when nothing is filled in. What is no
 * number is kept as typed for the core check to refuse. The check is the core's: rules() adds none.
 */
final class LocationFieldType implements FieldType, DisplaysInAdmin
{
    /** @param (\Closure(): ?LocationPicker)|null $picker the bound LocationPicker, if any */
    public function __construct(
        private readonly ?\Closure $picker = null,
    ) {}

    public function key(): string
    {
        return 'location';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = static fn (string $key): string => $e(FolioText::get('folio.fields.location.' . $key));
        $data = Link::decode($ctx->value) ?? [];
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = 'folio-location-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ctx->name), '-');
        $hasError = $ctx->errors !== [];
        $text = static fn (mixed $v): string => is_int($v) || is_float($v) ? Location::coordinate((float) $v) : (is_string($v) ? $v : '');
        $ids = ['lat' => $id . '-lat', 'lng' => $id . '-lng', 'address' => $id . '-address'];

        $html = '<fieldset class="form-group folio-location' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        $html .= '  <div class="folio-location-row">';
        foreach (['lat' => ['-90', '90'], 'lng' => ['-180', '180']] as $part => [$min, $max]) {
            $html .= '<div class="form-group"><label for="' . $e($ids[$part]) . '">' . $t($part) . '</label>'
                . '<input type="number" id="' . $e($ids[$part]) . '" name="' . $e($ctx->name) . '[' . $part . ']" value="' . $e($text($data[$part] ?? null)) . '"'
                . ' step="any" min="' . $min . '" max="' . $max . '" inputmode="decimal" placeholder="' . $t($part . '_placeholder') . '"></div>';
        }
        $html .= "</div>\n";
        $html .= '  <div class="form-group"><label for="' . $e($ids['address']) . '">' . $t('address') . '</label>'
            . '<input type="text" id="' . $e($ids['address']) . '" name="' . $e($ctx->name) . '[address]" value="' . $e($text($data['address'] ?? null)) . '" autocomplete="off"></div>' . "\n";
        $picker = $this->picker();
        if ($picker !== null) {
            $html .= '  <div class="folio-location-picker">' . $picker->editorHtml($ids, Location::fromStored($data), $ctx->config) . "</div>\n";
        } else {
            $html .= '  <small class="form-help">' . $t('hint') . '</small>' . "\n";
        }
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /**
     * {lat, lng, address}: a number typed (a decimal comma read as a point) is a number, anything
     * else stays as typed for the check; the address trimmed, null when empty. Null when nothing is
     * filled in.
     *
     * @return array<string, mixed>|null
     */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        $raw = Link::decode($raw);
        if ($raw === null) {
            return null;
        }
        $part = static function (mixed $value): float|string|null {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
            if (!is_string($value) || trim($value) === '') {
                return null;
            }
            $typed = trim($value);
            $number = Location::number(preg_match('/^[+-]?\d+,\d+$/', $typed) === 1 ? str_replace(',', '.', $typed) : $typed);

            return $number ?? $typed;
        };
        $lat = $part($raw['lat'] ?? null);
        $lng = $part($raw['lng'] ?? null);
        $address = is_string($raw['address'] ?? null) && trim($raw['address']) !== '' ? trim($raw['address']) : null;

        return $lat === null && $lng === null && $address === null ? null : ['lat' => $lat, 'lng' => $lng, 'address' => $address];
    }

    /** The stored object (SQL storage writes JSON text of it): a place as Location stores it; what is no place as it is, for the check. */
    public function toStorage(mixed $value): mixed
    {
        return Location::fromStored($value)?->toStored() ?? Link::decode($value);
    }

    public function fromStorage(mixed $value): mixed
    {
        return Location::fromStored($value)?->toStored();
    }

    /** The address (or the coordinates) linked to the place's `geo:` address. */
    public function renderFrontend(mixed $value, array $config): string
    {
        $location = Location::fromStored($value);
        if ($location === null) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<a href="' . $e($location->geoHref()) . '">' . $e($location->address ?? Location::coordinate($location->lat) . ', ' . Location::coordinate($location->lng)) . '</a>';
    }

    /**
     * "47.3769, 8.5417 · Bahnhofplatz, Zürich" for a list cell or a read-only field.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $location = Location::fromStored($value);
        if ($location === null) {
            return '';
        }
        $text = Location::coordinate($location->lat) . ', ' . Location::coordinate($location->lng) . ($location->address !== null ? ' · ' . $location->address : '');

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** None of its own: the core's check (ValidLocation, through FieldKinds::rules()) applies in Folio too. */
    public function rules(array $config): array
    {
        return [];
    }

    /** The bound LocationPicker's files; none without one. */
    public function assets(): array
    {
        return $this->picker()?->editorAssets() ?? [];
    }

    private function picker(): ?LocationPicker
    {
        return $this->picker !== null ? ($this->picker)() : null;
    }
}
