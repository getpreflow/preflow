<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Link;

/**
 * The built-in player: a button (with the poster, when there is one, from the site's own media
 * library) that the visitor clicks to load the provider's player. Before the click the page loads
 * nothing from the provider: the iframe waits in an inert `<template>` and the button holds no
 * third-party image. A note says where the video plays from, with a link to it on the provider.
 *
 *     <div class="preflow-embed" data-preflow-embed data-provider="youtube">
 *       <button type="button" class="preflow-embed-play" data-preflow-embed-play aria-label="Play video: …">
 *         <img class="preflow-embed-poster" src="/poster.jpg" alt="" loading="lazy"> (with a poster)
 *         <span class="preflow-embed-icon" aria-hidden="true"><svg …></span><span class="preflow-embed-label">Play video</span>
 *       </button>
 *       <template data-preflow-embed-frame><iframe class="preflow-embed-frame" src="…" title="…" …></iframe></template>
 *       <p class="preflow-embed-note">The video plays from YouTube. <a href="…">Open on YouTube</a></p>
 *     </div>
 *
 * No inline handler and no style attribute: the script (assets/embed-player.js) and the few
 * layout rules (assets/embed-player.css) come through the page's asset collector with its nonce.
 *
 * @since 0.91.0
 */
final class PrivacyEmbedPlayer implements EmbedPlayer
{
    private const string ICON = '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>';

    public function html(EmbedView $view): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $html = '<div class="preflow-embed" data-preflow-embed data-provider="' . $e($view->embed->provider) . '">';
        $html .= '<button type="button" class="preflow-embed-play" data-preflow-embed-play aria-label="' . $e($view->text('play_named')) . '">';
        if ($view->posterUrl !== null && $view->posterUrl !== '') {
            $html .= '<img class="preflow-embed-poster" src="' . $e($view->posterUrl) . '" alt="" loading="lazy">';
        }
        $html .= '<span class="preflow-embed-icon" aria-hidden="true">' . self::ICON . '</span><span class="preflow-embed-label">' . $e($view->text('play')) . '</span></button>';
        $html .= '<template data-preflow-embed-frame><iframe class="preflow-embed-frame" src="' . $e($view->playerUrl) . '" title="' . $e($view->title) . '" width="560" height="315"'
            . ' allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></template>';
        $html .= '<p class="preflow-embed-note">' . $e($view->text('note'));
        if (Link::isSiteOrWebAddress($view->embed->url)) {
            $html .= ' <a href="' . $e($view->embed->url) . '" rel="noopener noreferrer">' . $e($view->text('open')) . '</a>';
        }

        return $html . '</p></div>';
    }
}
