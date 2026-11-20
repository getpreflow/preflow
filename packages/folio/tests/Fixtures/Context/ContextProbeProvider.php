<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Context;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\CookieQueue;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * Template functions for FolioContextTest:
 * - context_probe(): the current render's context as "locale|surface|reads|viewer|purpose";
 * - context_after(): queues after-response work that records the probe as it is then;
 * - probe_cookie(): queues the cookie "probe=1" on the current frame;
 * - probe_effects(): writes a note when $write is on, queues a mail (notify) when $mail is on,
 *   throws when $fail is on.
 */
final class ContextProbeProvider extends ServiceProvider
{
    /** @var list<string> every context_probe() printed */
    public static array $seen = [];

    /** @var list<string> what each context_after() work saw */
    public static array $after = [];

    /** probe_effects() writes a note (a page that writes on GET). */
    public static bool $write = false;

    /** probe_effects() queues a mail (a page that mails on GET). */
    public static bool $mail = false;

    /** probe_effects() throws (a page that fails). */
    public static bool $fail = false;

    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $engine = $container->get(TemplateEngineInterface::class);
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'context_probe',
            callable: static function (): string {
                return self::$seen[] = self::probe();
            },
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'context_after',
            callable: static function (): string {
                Contexts::frame()->outbox()->afterResponse(static function (): void {
                    self::$after[] = self::probe();
                });

                return '';
            },
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'probe_effects',
            callable: static function () use ($container): string {
                if (self::$fail) {
                    throw new \RuntimeException('The probed page failed.');
                }
                if (self::$write) {
                    $dm = $container->get(\Preflow\Data\DataManager::class);
                    $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => bin2hex(random_bytes(4)), 'name' => 'Visited']), false);
                }
                if (self::$mail) {
                    $container->get(\Preflow\Notify\Notifier::class)->sendLater(new \Preflow\Notify\Email('owner@site.test', 'Visited', 'Someone read the page.'));
                }

                return '';
            },
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'probe_cookie',
            callable: static function (): string {
                CookieQueue::ofCurrentFrame()->queue('probe=1; Path=/');

                return '';
            },
        ));
    }

    public static function probe(): string
    {
        $ctx = Contexts::current();

        return implode('|', [
            $ctx->locale,
            $ctx->surface->key(),
            $ctx->reads->key(),
            $ctx->viewer->primary()?->getAuthId() ?? 'nobody',
            $ctx->purpose->value,
        ]);
    }
}
