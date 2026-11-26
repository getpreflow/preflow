<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Location;

/**
 * Optional: a map (or a place search) for a location field's editor. Without one, the editor has
 * number inputs for latitude and longitude and a line for the address; with one bound, its
 * markup is added inside the field, below those inputs, and fills them when a place is picked.
 * The inputs stay the field's value: what they hold is what is saved and checked.
 *
 *     $container->singleton(\Preflow\Fields\LocationPicker::class, fn () => new MyMapPicker());
 *
 * Folio draws it (Folio's location input); another editor may too. A picker's map tiles or search
 * come from a third party: the admin's Content-Security-Policy must allow them (img-src,
 * connect-src), and the script it needs is one of editorAssets().
 *
 * @since 0.91.0
 */
interface LocationPicker
{
    /**
     * The picker's markup for one location field. It finds the inputs by their ids, reads the
     * current place from them, and writes a picked place into them (and fires an "input" event on
     * each, so the editor sees the change). No inline script or style: those break the admin's CSP.
     *
     * @param array{lat: string, lng: string, address: string} $inputIds the ids of the field's inputs
     * @param array<string, mixed> $config the field's config
     */
    public function editorHtml(array $inputIds, ?Location $value, array $config): string;

    /**
     * The files the markup needs, by the names the editor serves them under (in Folio: names the
     * site registered with Folio's AdminAssets::add(), such as "map-picker.js").
     *
     * @return list<string>
     */
    public function editorAssets(): array;
}
