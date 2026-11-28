<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Preview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Preview\PreviewConfigException;
use Preflow\Blocks\Preview\PreviewView;
use Preflow\Blocks\Preview\TypePreview;

/** A type's "preview" option: its views read from the schema, refused when the shape cannot work. */
final class TypePreviewTest extends TestCase
{
    public function test_types_without_preview_are_unchanged(): void
    {
        $this->assertNull(TypePreview::fromSchema('job', ['key' => 'job', 'fields' => ['title' => ['type' => 'string']]]));
    }

    public function test_short_form_is_one_default_view(): void
    {
        $preview = TypePreview::fromSchema('job', ['preview' => ['component' => 'Teaser']]);

        $this->assertNotNull($preview);
        $this->assertSame('job', $preview->type);
        $this->assertSame(['default'], $preview->keys());
        $view = $preview->view(null);
        $this->assertInstanceOf(PreviewView::class, $view);
        $this->assertSame('default', $view->key);
        $this->assertNull($view->label);
        $this->assertSame('Teaser', $view->component);
    }

    public function test_views_keep_their_order_and_first_is_default(): void
    {
        $preview = TypePreview::fromSchema('job', ['preview' => ['views' => [
            'wide' => ['label' => 'Wide', 'component' => 'TeaserWide'],
            'card' => ['component' => 'TeaserCard'],
        ]]]);

        $this->assertSame(['wide', 'card'], $preview?->keys());
        $this->assertSame(['wide', 'card'], array_keys($preview->views));
        $this->assertSame('wide', $preview->view(null)->key);
        $this->assertSame('Wide', $preview->view('wide')->label);
        $this->assertSame('TeaserWide', $preview->view('wide')->component);
        $this->assertSame('card', $preview->view('card')->key);
        $this->assertNull($preview->view('card')->label);
        $this->assertSame('TeaserCard', $preview->view('card')->component);
    }

    public function test_unknown_view_falls_back_to_the_first(): void
    {
        $preview = TypePreview::fromSchema('job', ['preview' => ['views' => [
            'card' => ['component' => 'TeaserCard'],
            'wide' => ['component' => 'TeaserWide'],
        ]]]);

        $this->assertSame('card', $preview?->view('gone')->key);
        $this->assertSame('card', $preview->view('page')->key, 'page is Folio\'s, not a view');
        $this->assertSame('card', $preview->view('')->key);
    }

    #[DataProvider('refusals')]
    public function test_refusals_name_the_type_and_what_to_do(mixed $preview, string $message): void
    {
        try {
            TypePreview::fromSchema('job', ['preview' => $preview]);
            $this->fail('the preview was read');
        } catch (PreviewConfigException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @return array<string, array{mixed, string}> */
    public static function refusals(): array
    {
        $object = 'Type "job": "preview" must be an object such as {"component": "Teaser"} or {"views": {"card": {"label": "Card", "component": "TeaserCard"}}}.';
        $oneOf = 'Type "job": "preview" must set exactly one of "component" (one view, such as {"component": "Teaser"}) or "views" (several, such as {"views": {"card": {"label": "Card", "component": "TeaserCard"}}}).';
        $views = 'Type "job": "preview.views" must be a non-empty object of views, such as {"card": {"label": "Card", "component": "TeaserCard"}}.';
        $badKey = static fn (string $key): string => sprintf('Type "job": the preview view "%s" has a key that is not allowed; use lowercase letters, digits, "_" and "-", starting with a letter, such as "card".', $key);

        return [
            'not an object (text)' => ['Teaser', $object],
            'not an object (a list)' => [['Teaser'], $object],
            'neither component nor views' => [[], $oneOf],
            'both component and views' => [['component' => 'Teaser', 'views' => ['card' => ['component' => 'TeaserCard']]], $oneOf],
            'component not a name' => [['component' => ''], 'Type "job": "preview.component" must be the name of a component, such as "Teaser".'],
            'empty views' => [['views' => []], $views],
            'views a list' => [['views' => [['component' => 'TeaserCard']]], $views],
            'bad view key (capital)' => [['views' => ['Card' => ['component' => 'TeaserCard']]], $badKey('Card')],
            'bad view key (digit first)' => [['views' => ['card' => ['component' => 'A'], '2col' => ['component' => 'B']]], $badKey('2col')],
            'reserved page' => [['views' => ['page' => ['component' => 'TeaserCard']]], 'Type "job": the preview view "page" is reserved for the preview of the type\'s route; name the view differently, such as "full".'],
            'view not an object' => [['views' => ['card' => 'TeaserCard']], 'Type "job": the preview view "card" must be an object such as {"label": "Card", "component": "TeaserCard"}.'],
            'view without component' => [['views' => ['card' => ['label' => 'Card']]], 'Type "job": the preview view "card" has no "component"; name the component that renders it, such as {"component": "TeaserCard"}.'],
            'view with an empty component' => [['views' => ['card' => ['component' => '']]], 'Type "job": the preview view "card" has no "component"; name the component that renders it, such as {"component": "TeaserCard"}.'],
            'non-string label' => [['views' => ['card' => ['label' => ['en' => 'Card'], 'component' => 'TeaserCard']]], 'Type "job": the preview view "card" has a "label" that is not text; give text such as "Card" (a translation key works too), or leave it out.'],
            'unknown key' => [['component' => 'Teaser', 'css' => 'site.css'], 'Type "job": "preview" has the unknown key "css"; it takes "component" (one view) or "views" (several).'],
            'unknown key in a view' => [['views' => ['card' => ['component' => 'TeaserCard', 'props' => []]]], 'Type "job": the preview view "card" has the unknown key "props"; a view takes "component" and "label".'],
        ];
    }
}
