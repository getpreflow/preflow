<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Fields\EmbedOptions;
use Preflow\Fields\EmbedProvider;
use Preflow\Fields\EmbedProviders;
use Preflow\Fields\EmbedView;
use Preflow\Fields\FieldOptions;
use Preflow\Fields\PrivacyEmbedPlayer;
use Preflow\Fields\Rules\ValidEmbed;
use Preflow\Fields\Values\Embed;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/** The video embed field's value, providers, options, check and player (0.91). */
final class EmbedTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function addresses(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ'];
        yield 'watch with more parameters' => ['https://youtube.com/watch?feature=share&v=aqz-KE-bpKQ&t=42s', 'youtube', 'aqz-KE-bpKQ'];
        yield 'mobile watch' => ['https://m.youtube.com/watch?v=aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ'];
        yield 'short link' => ['https://youtu.be/aqz-KE-bpKQ?si=abc', 'youtube', 'aqz-KE-bpKQ'];
        yield 'shorts' => ['https://www.youtube.com/shorts/aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ'];
        yield 'embed' => ['https://www.youtube.com/embed/aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ'];
        yield 'no-cookie embed' => ['https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?start=3', 'youtube', 'aqz-KE-bpKQ'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871'];
        yield 'vimeo private link' => ['https://vimeo.com/76979871/8272103f6e', 'vimeo', '76979871:8272103f6e'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo', '76979871'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?h=8272103f6e', 'vimeo', '76979871:8272103f6e'];
    }

    #[DataProvider('addresses')]
    public function test_each_address_form_is_recognized(string $url, string $provider, string $id): void
    {
        $embed = Embed::fromUrl($url, new EmbedProviders());

        $this->assertNotNull($embed, $url);
        $this->assertSame($provider, $embed->provider);
        $this->assertSame($id, $embed->id);
        $this->assertSame($url, $embed->url, 'the address is kept as pasted');
        $this->assertSame(['provider' => $provider, 'id' => $id, 'url' => $url, 'poster' => null], $embed->toStored());
    }

    public function test_other_addresses_are_not_videos(): void
    {
        foreach (['https://www.youtube.com/', 'https://www.youtube.com/watch?v=short', 'https://youtube.com.example.org/watch?v=aqz-KE-bpKQ', 'https://vimeo.com/about', 'javascript:alert(1)', 'https://example.org/video.mp4', 'youtu.be/aqz-KE-bpKQ', ''] as $url) {
            $this->assertNull(Embed::fromUrl($url, new EmbedProviders()), $url);
        }
    }

    public function test_the_player_addresses_make_no_cookies_and_start_on_load(): void
    {
        $providers = new EmbedProviders();

        $this->assertSame('https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1', Embed::fromUrl('https://youtu.be/aqz-KE-bpKQ', $providers)?->playerUrl($providers));
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1&autoplay=1', Embed::fromUrl('https://vimeo.com/76979871', $providers)?->playerUrl($providers));
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1&autoplay=1&h=8272103f6e', Embed::fromUrl('https://vimeo.com/76979871/8272103f6e', $providers)?->playerUrl($providers));
    }

    public function test_a_stored_value_reads_back_from_the_object_json_text_or_a_plain_address(): void
    {
        $stored = ['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => 'a1'];

        $this->assertSame($stored, Embed::fromStored($stored)?->toStored());
        $this->assertSame($stored, Embed::fromStored((string) json_encode($stored))?->toStored());
        $this->assertSame('aqz-KE-bpKQ', Embed::fromStored('https://youtu.be/aqz-KE-bpKQ')?->id);
        foreach ([null, '', [], 'not a video', ['url' => 'x'], 5] as $none) {
            $this->assertNull(Embed::fromStored($none), (string) json_encode($none));
        }
    }

    public function test_a_provider_a_site_adds_is_offered_only_where_a_field_lists_it(): void
    {
        $providers = new EmbedProviders();
        $providers->add(self::peerTube());
        $url = 'https://video.example.org/w/kT2pQx';

        $this->assertNull(Embed::fromUrl($url, $providers), 'not listed: refused');
        $embed = Embed::fromUrl($url, $providers, ['peertube']);
        $this->assertSame(['provider' => 'peertube', 'id' => 'kT2pQx', 'url' => $url, 'poster' => null], $embed?->toStored());
        $this->assertSame('https://video.example.org/videos/embed/kT2pQx', $embed?->playerUrl($providers));
        $this->assertNull($embed?->playerUrl(new EmbedProviders()), 'a provider no longer registered plays nothing');
    }

    public function test_the_check_names_the_refused_host_and_what_to_paste_instead(): void
    {
        $rule = new ValidEmbed(new EmbedOptions(), new EmbedProviders());
        $ctx = new ValidationContext('video', []);

        $refusal = $rule->validate('https://www.dailymotion.com/video/x8abc', $ctx);
        $this->assertInstanceOf(Message::class, $refusal);
        $this->assertSame('validation.embed_provider', $refusal->key);
        $this->assertSame(['host' => 'www.dailymotion.com', 'providers' => 'YouTube, Vimeo'], $refusal->params);
        $this->assertSame('Videos from www.dailymotion.com cannot be embedded here; paste the address of a video on YouTube, Vimeo.', (string) $refusal);

        $refusal = $rule->validate('just text', $ctx);
        $this->assertInstanceOf(Message::class, $refusal);
        $this->assertSame('Must be the address of a video on YouTube, Vimeo, such as https://www.youtube.com/watch?v=aqz-KE-bpKQ.', (string) $refusal);

        $this->assertTrue($rule->validate(null, $ctx));
        $this->assertTrue($rule->validate('', $ctx));
        $this->assertTrue($rule->validate('https://youtu.be/aqz-KE-bpKQ', $ctx), 'a plain address (a seed, the REST API)');
        $this->assertTrue($rule->validate(['provider' => 'vimeo', 'id' => '76979871', 'url' => 'https://vimeo.com/76979871', 'poster' => 'a1'], $ctx));
    }

    public function test_the_check_refuses_a_stored_id_that_is_not_the_addresses_and_a_bad_poster(): void
    {
        $rule = new ValidEmbed(new EmbedOptions(), new EmbedProviders());
        $ctx = new ValidationContext('video', []);

        $mismatch = $rule->validate(['provider' => 'youtube', 'id' => 'xxxxxxxxxxx', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => null], $ctx);
        $this->assertInstanceOf(Message::class, $mismatch);
        $this->assertSame('validation.embed', $mismatch->key, 'the page would play another video than the address says');

        $poster = $rule->validate(['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => ['a1']], $ctx);
        $this->assertInstanceOf(Message::class, $poster);
        $this->assertSame('The poster must be an image of the media library; choose one or remove it.', (string) $poster);
    }

    public function test_a_listed_provider_the_site_never_registered_is_named(): void
    {
        $rule = new ValidEmbed(EmbedOptions::fromConfig(['providers' => ['peertube']]), new EmbedProviders());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The embed field lists the provider "peertube", but no EmbedProvider of that name is registered: add it with EmbedProviders::add() in a service provider, or remove it from "providers".');

        $rule->validate('https://youtu.be/aqz-KE-bpKQ', new ValidationContext('video', []));
    }

    public function test_the_options_are_checked_at_boot(): void
    {
        FieldOptions::check('embed', ['providers' => ['peertube']]);
        foreach ([
            [['translatable' => true], 'An embed field cannot be translatable; it holds the same value in every language. Remove "translatable".'],
            [['embed' => ['providers' => ['peertube']]], '"embed" holds options, but an embed field takes them at its top level, next to "type": move "providers" out of "embed".'],
            [['providers' => 'peertube'], '"providers" must be a list of provider names such as ["peertube"] (YouTube and Vimeo are always offered).'],
            [['providers' => ['youtube']], '"providers" lists "youtube", which is always offered; list only the providers your site adds.'],
            [['providers' => ['Peer Tube']], '"providers" lists "Peer Tube"; a provider name is lowercase letters, digits, - and _.'],
        ] as [$config, $message]) {
            try {
                FieldOptions::check('embed', $config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_the_player_makes_no_third_party_request_before_the_click(): void
    {
        $providers = new EmbedProviders();
        $embed = Embed::fromUrl('https://www.youtube.com/watch?v=aqz-KE-bpKQ', $providers, [], 'poster1');
        $this->assertNotNull($embed);
        $html = (new PrivacyEmbedPlayer())->html(new EmbedView($embed, 'YouTube', (string) $embed->playerUrl($providers), '/media/poster.jpg', 'Our <film>', 'en'));

        // Outside the inert <template> nothing loads from anywhere but the site: no iframe, no
        // script, no src or srcset at all except the site's own poster.
        $outside = (string) preg_replace('~<template\b[^>]*>.*?</template>~s', '', $html);
        $this->assertStringNotContainsString('<iframe', $outside);
        $this->assertStringNotContainsString('<script', $outside);
        $this->assertStringNotContainsString('srcset', $outside);
        preg_match_all('~\ssrc="([^"]*)"~', $outside, $sources);
        $this->assertSame(['/media/poster.jpg'], $sources[1]);
        $this->assertDoesNotMatchRegularExpression('~(src|srcset|poster|data|style)="[^"]*(youtube|ytimg|vimeo)~i', $outside, 'no third-party resource before activation');
        $this->assertStringNotContainsString('youtube.com/embed', $html);

        // The click loads the no-cookie player, which the template holds; the iframe has a title.
        $this->assertMatchesRegularExpression('~<template data-preflow-embed-frame><iframe [^>]*src="https://www\.youtube-nocookie\.com/embed/aqz-KE-bpKQ\?autoplay=1"[^>]*title="Our &lt;film&gt;"~', $html);
        // A real button, keyboard-operable, named for people.
        $this->assertMatchesRegularExpression('~<button type="button" class="preflow-embed-play" data-preflow-embed-play aria-label="Play video: Our &lt;film&gt;">~', $html);
        $this->assertStringContainsString('The video plays from YouTube.', $html);
        // No inline handlers and no style attributes (the site's CSP forbids them).
        $this->assertDoesNotMatchRegularExpression('~\son[a-z]+=|\sstyle=~i', $html);
    }

    public function test_the_player_speaks_the_pages_language(): void
    {
        $providers = new EmbedProviders();
        $embed = Embed::fromUrl('https://vimeo.com/76979871', $providers);
        $this->assertNotNull($embed);
        $html = (new PrivacyEmbedPlayer())->html(new EmbedView($embed, 'Vimeo', (string) $embed->playerUrl($providers), null, null, 'de'));

        $this->assertStringContainsString('aria-label="Video abspielen: Video (Vimeo)"', $html);
        $this->assertStringContainsString('Das Video wird von Vimeo geladen.', $html);
        $this->assertStringNotContainsString('<img', $html, 'no poster: no image, and never the provider\'s thumbnail');
    }

    private static function peerTube(): EmbedProvider
    {
        return new class implements EmbedProvider {
            public function name(): string
            {
                return 'peertube';
            }

            public function label(): string
            {
                return 'PeerTube';
            }

            public function idOf(string $url): ?string
            {
                return preg_match('~^https://video\.example\.org/w/([A-Za-z0-9]+)$~', $url, $m) === 1 ? $m[1] : null;
            }

            public function playerUrl(string $id): string
            {
                return 'https://video.example.org/videos/embed/' . rawurlencode($id);
            }
        };
    }
}
