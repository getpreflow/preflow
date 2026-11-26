<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * A video host an embed field can play: which addresses are its videos, and the player address
 * the page loads once a visitor starts the video (never before: see PrivacyEmbedPlayer).
 *
 * YouTube and Vimeo are built in (Embed\YouTube, Embed\Vimeo). A site adds another host by
 * implementing this and adding it to the EmbedProviders in a service provider's boot()
 * ($container->get(EmbedProviders::class)->add(new MyHost())); a field offers it only when its
 * "providers" option lists the name.
 *
 * @since 0.91.0
 */
interface EmbedProvider
{
    /** The provider's stored name ("youtube"): lowercase letters, digits, - and _. */
    public function name(): string;

    /** The provider's name for people ("YouTube"). */
    public function label(): string;

    /** The video's id in an address of this provider (as pasted from the browser); null when it is no video of this provider. */
    public function idOf(string $url): ?string;

    /** The address of the player for the video with $id: the iframe's src, loaded only after a click. */
    public function playerUrl(string $id): string;
}
