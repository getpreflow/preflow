<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.94.0: view 0.86.0 (SafeHtml, which every engine prints as it is: PreviewCanvas hands the component's HTML
 * to the canvas template with it; an older view would print it escaped).
 * Since 0.94.0: data 0.94.0 (the type's "preview" option, which TypePreviews reads; FieldKinds::rulesOf() with the
 * field); validation 0.94.0 (the block count's messages, "validation.blocks.*": an older one would show their keys);
 * folio 0.94.0 (Folio before 0.94 merges a block list without LayoutInput::merge()'s hiddenFields, so every save of
 * a page with a block field hidden in "_areas" would be refused as an unknown field).
 * Since 0.93.0: data 0.93.0 (Values\FieldValue and its companions, FieldKinds::value(), StoredCleaning and
 * DataManager::cleaning(): the blocks and areas values, block rich text cleaned on write, the "_areas" system key's
 * cleaning, and CoreBlockTextCleaner over the core cleaner).
 * Since 0.92.1: validation 0.92.1 (Message's count, which chooses the plural form of the area limits' messages;
 * an older validation has no count, and its texts have one form).
 * Since 0.92.0: data 0.92.0 (RecordRules, DataManager::recordRules(): the page layout rules run on every
 * validated save of a type with "layouts", and a pinned page's slug also on drafts); validation 0.92.0 (the
 * layout rules' messages, "validation.layout.*": an older one would show their keys); folio 0.92.0 (the block values
 * are checked here, BlockValueChecks; Folio before 0.92 walks them itself too, so each message would show twice).
 * Since 0.91.0: data 0.91.0 (FieldVisibility, with which the registry checks a block field's show_when and
 * visibleBlock() reads a hidden one as empty); folio 0.91.0 (Folio before 0.91 validates a hidden block field, so
 * a required field a block hides would block every save, and its editor would show it).
 * Since 0.90.0: data 0.90.0 (SelectOptionsFrom, which checks a block select's "options_from" when the
 * registry is built).
 * Since 0.81.0: core 0.81.0 (Context\Contexts, RenderContext and Deprecations: blocks render in the render
 * context's locale through ContextBlockLocale, and a BlockLocale without it is reported).
 * Since 0.75: data 0.75 (TypeFamily, which #[Block(family: …)] and BlockDefinition::$family carry).
 *
 * "^0.70 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing blocks with a release that lacks the APIs it calls: core 0.70 (boot manifest
 * catalogs, installedPackages(), CacheRegistry, PageCache::refuse()), components 0.70 (root attributes,
 * ComponentMap), data 0.70 (LocaleValue::resolveTree(), TypeFieldDefinition), view 0.70 (template functions).
 * folio 0.71 (before 0.70 Folio ships its own block classes, registry and renderer, which clash with this package's; 0.70
 * still passes BlocksConfig::fromApp() a fallback and reads extra.folio.blocks, which 0.71 dropped, so a site's old
 * block settings would silently stop counting; it is not required: it carries the blocks field and its editor, and
 * registers this package itself since 0.70). folio 0.83 since blocks 0.83: Folio before 0.83 implements ContextBlockLocale,
 * which 0.83 merged into BlockLocale. folio 0.90.0 since blocks 0.90.0: blocks no longer refuse a select's
 * "options_from", and Folio before 0.90 validates a block without the block's other fields, so every save of such
 * a block would fail.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.94.0 || @dev', $composer['require']['preflow/data'] ?? null);
        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.94.0 || @dev', $composer['require']['preflow/validation'] ?? null);
        $this->assertSame('^0.86.0 || @dev', $composer['require']['preflow/view'] ?? null);
        $this->assertSame([
            'preflow/components' => '<0.70',
            'preflow/core' => '<0.81.0',
            'preflow/data' => '<0.94.0',
            'preflow/folio' => '<0.94.0',
            'preflow/validation' => '<0.94.0',
            'preflow/view' => '<0.86.0',
        ], $composer['conflict'] ?? null);
    }
}
