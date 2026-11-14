<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\View\FolioEngine;
use Preflow\Folio\View\InkMarks;

/**
 * The ink layer's marks (INK.md): a fixed seed places the same marks every time, inside the box's
 * area (fractions of the box), so a template can keep them away from its text.
 */
final class InkMarksTest extends TestCase
{
    use StaffScaffold;

    private const AREA = ['x' => [0.5, 1.0], 'y' => [0, 1]];

    public function test_the_generator_is_mulberry32(): void
    {
        // Reference values from the JavaScript mulberry32, so a port in the browser places the same marks.
        $this->assertEqualsWithDelta([0.6270739405881613, 0.002735721180215478, 0.5274470399599522], InkMarks::sequence(1, 3), 1e-12);
        $this->assertEqualsWithDelta([0.6011037519201636, 0.44829055899754167, 0.8524657934904099], InkMarks::sequence(42, 3), 1e-12);
        $this->assertEqualsWithDelta([0.43306733411736786, 0.32539576734416187, 0.5442695003002882], InkMarks::sequence(-7, 3), 1e-12);
    }

    public function test_the_same_seed_gives_the_same_marks(): void
    {
        $marks = InkMarks::place(2026, 20, self::AREA);
        $this->assertCount(20, $marks);
        $this->assertSame($marks, InkMarks::place(2026, 20, self::AREA));
        $this->assertNotSame($marks, InkMarks::place(2027, 20, self::AREA), 'another seed, other marks');

        foreach ($marks as $mark) {
            $this->assertSame(['mark', 'x', 'y', 'size', 'rotate', 'hue'], array_keys($mark));
            $this->assertContains($mark['mark'], InkMarks::MARKS);
            $this->assertIsFloat($mark['x']);
            $this->assertIsFloat($mark['y']);
            $this->assertIsFloat($mark['size']);
            $this->assertIsInt($mark['rotate']);
            $this->assertGreaterThanOrEqual(-45, $mark['rotate']);
            $this->assertLessThanOrEqual(45, $mark['rotate']);
            $this->assertContains($mark['hue'], [1, 2, 3, 4, 5, 6]);
        }
        $this->assertGreaterThan(3, count(array_unique(array_column($marks, 'mark'))), 'a mix of marks');
        $this->assertGreaterThan(3, count(array_unique(array_column($marks, 'hue'))), 'a mix of hues');
    }

    public function test_the_marks_stay_inside_the_area_with_their_size(): void
    {
        $areas = [self::AREA, ['x' => [0, 0.4], 'y' => [0.2, 0.9]], ['x' => [0.6, 1], 'y' => [0, 0.5]]];
        foreach ($areas as $area) {
            foreach ([1, 7, 99, 2026] as $seed) {
                foreach (InkMarks::place($seed, 60, $area) as $mark) {
                    $this->assertGreaterThanOrEqual($area['x'][0], $mark['x']);
                    $this->assertLessThanOrEqual($area['x'][1], $mark['x']);
                    $this->assertGreaterThanOrEqual($area['y'][0], $mark['y']);
                    $this->assertLessThanOrEqual($area['y'][1], $mark['y']);
                    // The drawn mark (its whole size) stays inside the area in boxes of any size that fit it.
                    foreach ([[1100, 160], [640, 960], [360, 220]] as [$w, $h]) {
                        [$left, $top, $right, $bottom] = InkMarks::box($mark, $area, $w, $h);
                        $this->assertEqualsWithDelta($mark['size'], $right - $left, 1e-6);
                        $this->assertEqualsWithDelta($mark['size'], $bottom - $top, 1e-6);
                        if (($area['x'][1] - $area['x'][0]) * $w >= $mark['size']) {
                            $this->assertGreaterThanOrEqual($area['x'][0] * $w - 1e-6, $left, "{$w}×{$h}");
                            $this->assertLessThanOrEqual($area['x'][1] * $w + 1e-6, $right, "{$w}×{$h}");
                        }
                        if (($area['y'][1] - $area['y'][0]) * $h >= $mark['size']) {
                            $this->assertGreaterThanOrEqual($area['y'][0] * $h - 1e-6, $top, "{$w}×{$h}");
                            $this->assertLessThanOrEqual($area['y'][1] * $h + 1e-6, $bottom, "{$w}×{$h}");
                        }
                    }
                }
            }
        }
    }

    public function test_an_odd_area_is_read_safely(): void
    {
        // Swapped ends, values outside 0..1 and a missing axis: the area is clamped to the box.
        foreach (InkMarks::place(5, 30, ['x' => [1.4, 0.7]]) as $mark) {
            $this->assertGreaterThanOrEqual(0.7, $mark['x']);
            $this->assertLessThanOrEqual(1.0, $mark['x']);
            $this->assertGreaterThanOrEqual(0.0, $mark['y']);
            $this->assertLessThanOrEqual(1.0, $mark['y']);
        }
    }

    public function test_the_count_is_clamped_to_0_to_60(): void
    {
        $this->assertSame([], InkMarks::place(1, 0, self::AREA));
        $this->assertSame([], InkMarks::place(1, -5, self::AREA));
        $this->assertCount(60, InkMarks::place(1, 61, self::AREA));
        $this->assertCount(60, InkMarks::place(1, 1000, self::AREA));
        $this->assertCount(6, InkMarks::place(1, 6, self::AREA));
    }

    public function test_the_svg_is_hidden_and_uses_only_the_marks(): void
    {
        $svg = InkMarks::svg('/folio/_assets/icons.svg?v=abc', 3, 12, self::AREA);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg), 'well-formed');
        $root = $doc->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('svg', $root->nodeName);
        $this->assertSame('folio-ink-marks', $root->getAttribute('class'));
        $this->assertSame('true', $root->getAttribute('aria-hidden'));
        $this->assertSame('false', $root->getAttribute('focusable'));

        $uses = $doc->getElementsByTagName('use');
        $this->assertCount(12, $uses);
        $marks = InkMarks::place(3, 12, self::AREA);
        foreach ($uses as $i => $use) {
            $this->assertMatchesRegularExpression('~^/folio/_assets/icons\.svg\?v=abc#mark-(caret|pilcrow|brace|block|cursor|squiggle|plus|ring|dot)$~', $use->getAttribute('href'));
            $this->assertSame('/folio/_assets/icons.svg?v=abc#mark-' . $marks[$i]['mark'], $use->getAttribute('href'));
            $this->assertSame('mc-' . $marks[$i]['hue'], $use->getAttribute('class'));
            // Each mark sits in its own viewport at the point, as percentages of the box.
            $place = $use->parentNode;
            $this->assertInstanceOf(\DOMElement::class, $place);
            $this->assertSame('svg', $place->nodeName);
            $this->assertEqualsWithDelta($marks[$i]['x'] * 100, (float) $place->getAttribute('x'), 0.01);
            $this->assertEqualsWithDelta($marks[$i]['y'] * 100, (float) $place->getAttribute('y'), 0.01);
        }
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertSame(0, $doc->getElementsByTagName('text')->length, 'no text: the layer says nothing');

        // The URL is the only string from outside and it is escaped.
        $this->assertStringContainsString('href="/x.svg?a=1&amp;b=&quot;2#mark-', InkMarks::svg('/x.svg?a=1&b="2', 3, 1, self::AREA));
        $this->assertSame('<svg class="folio-ink-marks" aria-hidden="true" focusable="false"></svg>', InkMarks::svg('/i.svg', 1, 0, self::AREA));
    }

    #[RunInSeparateProcess]
    public function test_templates_call_folio_ink_marks(): void
    {
        $this->staffSite();
        try {
            $probe = $this->dir . '/ink-probe';
            mkdir($probe);
            file_put_contents($probe . '/probe.twig', "<div>{{ folio_ink_marks(7, 6, {x: [0.5, 1], y: [0, 1]}) }}</div>");
            $engine = $this->flow->container()->get(FolioEngine::class);
            $engine->addNamespace('inkprobe', $probe);

            $html = $engine->render('@inkprobe/probe.twig');
            $icons = $this->flow->container()->get(\Preflow\Folio\Http\AdminAssetUrls::class)('icons.svg');
            // The markup comes through as markup (not escaped), with the versioned icons URL.
            $this->assertSame('<div>' . InkMarks::svg($icons, 7, 6, self::AREA) . '</div>', $html);
            $this->assertStringStartsWith('<div><svg class="folio-ink-marks" aria-hidden="true"', $html);
            $this->assertSame(6, substr_count($html, '<use '));
        } finally {
            $this->removeSite();
        }
    }
}
