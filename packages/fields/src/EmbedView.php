<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Embed;

/**
 * What a player needs to draw one video (EmbedPlayer::html()): the value, the provider's name for
 * people, the player address (the iframe's src, loaded only after a click), the poster's address
 * (null: none), the title (for the iframe's title and the button's name) and the page's language.
 *
 * A title not given is "Video (YouTube)" in the language. The players' texts are in TEXTS (en, de,
 * fr; any other language reads English): a site's own player may use text() or its own.
 *
 * @since 0.91.0
 */
final readonly class EmbedView
{
    /** The texts of the built-in player, per language; :provider and :title are replaced. */
    public const array TEXTS = [
        'en' => ['title' => 'Video (:provider)', 'play' => 'Play video', 'play_named' => 'Play video: :title', 'note' => 'The video plays from :provider.', 'open' => 'Open on :provider'],
        'de' => ['title' => 'Video (:provider)', 'play' => 'Video abspielen', 'play_named' => 'Video abspielen: :title', 'note' => 'Das Video wird von :provider geladen.', 'open' => 'Auf :provider öffnen'],
        'fr' => ['title' => 'Vidéo (:provider)', 'play' => 'Lire la vidéo', 'play_named' => 'Lire la vidéo : :title', 'note' => 'La vidéo est chargée depuis :provider.', 'open' => 'Ouvrir sur :provider'],
    ];

    /** The title: as given, else "Video (provider)" in the language. */
    public string $title;

    public function __construct(
        public Embed $embed,
        public string $providerLabel,
        public string $playerUrl,
        public ?string $posterUrl,
        ?string $title,
        public string $locale,
    ) {
        $this->title = $title !== null && trim($title) !== '' ? trim($title) : strtr(self::raw('title', $locale), [':provider' => $providerLabel]);
    }

    /** A text of TEXTS in the view's language (its first part: "de" for "de-CH"), English for another one. */
    public function text(string $key): string
    {
        return strtr(self::raw($key, $this->locale), [':provider' => $this->providerLabel, ':title' => $this->title]);
    }

    private static function raw(string $key, string $locale): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale), 2)[0]);

        return self::TEXTS[$language][$key] ?? self::TEXTS['en'][$key] ?? $key;
    }
}
