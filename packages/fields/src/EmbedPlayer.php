<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * Draws an embed field's player on a page: embed_player() in a template and Folio's frontend
 * output call the bound one. PrivacyEmbedPlayer is the default; a site that wants other markup
 * binds its own in a service provider:
 *
 *     $container->singleton(\Preflow\Fields\EmbedPlayer::class, fn () => new MyPlayer());
 *
 * The markup must load nothing from the video's host before the visitor starts it. The player
 * script that embed_player() adds runs any markup with the same three hooks: an element with
 * `data-preflow-embed`, a `<button data-preflow-embed-play>` in it, and a
 * `<template data-preflow-embed-frame>` holding the iframe; a click puts the template's content in
 * place of the button.
 *
 * @since 0.91.0
 */
interface EmbedPlayer
{
    /** The player's HTML, escaped and ready to print. */
    public function html(EmbedView $view): string;
}
