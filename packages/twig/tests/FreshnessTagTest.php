<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCache;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;
use Twig\Error\Error as TwigError;

/** {% freshness %} and {% expires %}: the same contract as Blade's @freshness and @expires (FreshnessDirectiveTest). */
final class FreshnessTagTest extends TestCase
{
    private string $dir;

    private DependencyRecorder $recorder;

    private float $now = 1_800_000_000.0;

    private TwigEngine $engine;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_freshness_twig_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->recorder = new DependencyRecorder();
        $this->engine = $this->engine(new PageCache(recorder: $this->recorder, clock: fn (): float => $this->now));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_reads_inside_are_tagged_and_the_innermost_scope_wins(): void
    {
        $this->template('page.twig', "{{ read('default:job#1') }}"
            . "{% freshness 'next-visit' %}[{{ read('default:job') }}"
            . "{% freshness 'timed' every '12h' %}{{ read('default:company') }}{% endfreshness %}"
            . "{{ read('default:news') }}]{% endfreshness %}"
            . "{% freshness 'timed' every 1800 %}{{ read('default:weather') }}{% endfreshness %}"
            . "{% freshness 'fresh' %}{{ read('default:fresh') }}{% endfreshness %}");

        [$html, $tags] = $this->recorder->capture(fn (): string => $this->engine->render('page.twig'));

        $this->assertSame('default:job#1[default:jobdefault:companydefault:news]default:weatherdefault:fresh', $html);
        $this->assertSame([
            'default:job#1' => [Freshness::Fresh, null],
            'default:job#' => [Freshness::Fresh, null],
            'default:job' => [Freshness::NextVisit, null],
            'default:company' => [Freshness::Timed, 43200],
            'default:news' => [Freshness::NextVisit, null],
            'default:weather' => [Freshness::Timed, 1800],
            'default:fresh' => [Freshness::Fresh, null],
        ], $tags);
    }

    public function test_the_strictest_freshness_wins_when_a_tag_is_read_twice(): void
    {
        $this->template('page.twig', "{% freshness 'timed' every '2d' %}{{ read('default:job') }}{% endfreshness %}"
            . "{% freshness 'next-visit' %}{{ read('default:job') }}{{ read('default:company') }}{% endfreshness %}"
            . "{{ read('default:company') }}");

        [, $tags] = $this->recorder->capture(fn (): string => $this->engine->render('page.twig'));

        $this->assertSame(['default:job' => [Freshness::NextVisit, null], 'default:company' => [Freshness::Fresh, null]], $tags);
    }

    public function test_the_body_sees_and_sets_the_variables_around_it_and_works_in_blocks(): void
    {
        $this->template('base.twig', '<main>{% block content %}{% endblock %}</main>');
        $this->template('page.twig', "{% extends 'base.twig' %}{% block content %}"
            . "{% set before = 'b' %}{% freshness name %}{{ before }}{% set inner = 'i' %}{{ read('default:job') }}{% endfreshness %}{{ inner }}"
            . "{% endblock %}");

        [$html, $tags] = $this->recorder->capture(fn (): string => $this->engine->render('page.twig', ['name' => 'next_visit']));

        $this->assertSame('<main>bdefault:jobi</main>', $html);
        $this->assertSame([Freshness::NextVisit, null], $tags['default:job']);
    }

    public function test_expires_at_and_in_keep_the_earliest(): void
    {
        $this->template('page.twig', "{% expires at event %}{% expires in '30m' %}{% expires at none %}{% expires at '2030-01-01 00:00:00 UTC' %}x");

        $frame = $this->recorder->start();
        $html = $this->engine->render('page.twig', ['event' => new \DateTimeImmutable('@' . (int) ($this->now + 7200)), 'none' => null]);
        $expiry = $this->recorder->expiry($frame);
        $this->recorder->stop($frame);

        $this->assertSame('x', $html);
        $this->assertSame($this->now + 1800, $expiry, '30 minutes is earlier than the event in 2 h');

        $frame = $this->recorder->start();
        $this->engine->render('page.twig', ['event' => new \DateTimeImmutable('@' . (int) ($this->now + 60)), 'none' => null]);
        $this->assertSame($this->now + 60, $this->recorder->expiry($frame));
        $this->recorder->stop($frame);
    }

    public function test_outside_a_recording_nothing_is_recorded_and_the_body_renders(): void
    {
        $this->template('page.twig', "{% freshness 'next-visit' %}{{ read('default:job') }}{% endfreshness %}{% expires in '1h' %}");

        $this->assertSame('default:job', $this->engine->render('page.twig'));
        $this->assertSame('default:job', $this->engine(null)->render('page.twig'), 'without the page cache too');
    }

    /** @return iterable<string, array{string}> */
    public static function mistakes(): iterable
    {
        yield 'unknown name' => ["{% freshness 'soon' %}x{% endfreshness %}"];
        yield 'timed without every' => ["{% freshness 'timed' %}x{% endfreshness %}"];
        yield 'every on next visit' => ["{% freshness 'next-visit' every '1h' %}x{% endfreshness %}"];
        yield 'bad duration' => ["{% freshness 'timed' every '1.5h' %}x{% endfreshness %}"];
        yield 'zero duration' => ["{% freshness 'timed' every '0m' %}x{% endfreshness %}"];
        yield 'expires in a date' => ["{% expires in 'tomorrow' %}"];
        yield 'expires at no date' => ["{% expires at 'not a date at all' %}"];
        yield 'every a bool' => ["{% freshness 'timed' every true %}x{% endfreshness %}"];
        yield 'an overflowing duration' => ["{% freshness 'timed' every '99999999999999999999d' %}x{% endfreshness %}"];
        yield 'expires without at or in' => ["{% expires '1h' %}"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mistakes')]
    public function test_mistakes_fail_with_and_without_the_page_cache(string $source): void
    {
        $this->template('page.twig', $source);
        foreach ([$this->engine, $this->engine(null)] as $engine) {
            try {
                $engine->render('page.twig');
                $this->fail('rendered: ' . $source);
            } catch (TwigError $e) {
                $this->assertStringContainsString('page.twig', $e->getMessage());
            }
        }
    }

    private function engine(?PageCache $cache): TwigEngine
    {
        $engine = new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator()), pageCache: $cache);
        $engine->addFunction(new TemplateFunctionDefinition('read', function (string $tag): string {
            $this->recorder->record($tag);

            return $tag;
        }));

        return $engine;
    }

    private function template(string $name, string $source): void
    {
        file_put_contents($this->dir . '/' . $name, $source);
    }
}
