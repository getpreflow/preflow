<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Notify\Email;
use Preflow\Notify\Mail\BasicMailLayout;
use Preflow\Notify\Mail\ComposeScope;
use Preflow\Notify\Mail\MailConfig;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportFactory;

/**
 * notify on its own, as its split repository runs it: its own queue and its own compose scope,
 * no class of preflow/core loaded on the way (each test runs in a process of its own and checks).
 */
final class NotifyWithoutCoreTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_queue_flush_and_discarding_queued_behave_as_before(): void
    {
        $factory = new TransportFactory(['mem' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'mem']]);
        $failed = false;

        $notifier->sendLater(new Email('before@x.test', 'S', 'T'));
        $result = $notifier->discardingQueued(function () use ($notifier, &$failed): string {
            $notifier->sendLater(new Email('during@x.test', 'S', 'T'), static function () use (&$failed): void {
                $failed = true;
            });

            return 'rendered';
        });
        $notifier->sendLater(new Email('after@x.test', 'S', 'T'));
        $sentNow = $notifier->send(new Email('now@x.test', 'S', 'T'));

        $this->assertSame('rendered', $result);
        $this->assertSame('mem', $sentNow->transport);
        $this->assertSame(2, $notifier->flushDeferred());
        $this->assertSame(0, $notifier->flushDeferred());
        $this->assertSame(['now@x.test', 'before@x.test', 'after@x.test'], array_map(static fn (SentMessage $s): string => $s->message->to[0], $factory->get('mem')->sent()));
        $this->assertFalse($failed);
        $this->assertNoCoreClassLoaded();
    }

    #[RunInSeparateProcess]
    public function test_compose_uses_the_explicit_lang_without_core(): void
    {
        $factory = new TransportFactory(['mem' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'mem']]);

        $email = (new Mailer($notifier, new BasicMailLayout(), new MailConfig()))->compose(new MailContent('Bonjour', 'Texte', 'fr'), 'a@x.test');
        $this->assertStringContainsString('<html lang="fr">', (string) $email->html);

        // A scope of the app's own (core passes one that renders in the recipient's context) gets the language.
        $scope = new class () implements ComposeScope {
            /** @var list<string> */
            public array $langs = [];

            public function run(string $lang, \Closure $compose): mixed
            {
                $this->langs[] = $lang;

                return $compose();
            }
        };
        $mailer = new Mailer($notifier, new BasicMailLayout(), new MailConfig(), $scope);
        $mailer->compose(new MailContent('Hallo', 'Text', 'de'), 'a@x.test');
        $mailer->sendLater(new MailContent('Bonjour', 'Texte', 'fr'), 'b@x.test');

        $this->assertSame(['de', 'fr'], $scope->langs);
        $this->assertSame(1, $notifier->flushDeferred());
        $this->assertNoCoreClassLoaded();
    }

    private function assertNoCoreClassLoaded(): void
    {
        $core = array_values(array_filter(
            array_merge(get_declared_classes(), get_declared_interfaces()),
            static fn (string $class): bool => str_starts_with($class, 'Preflow\\Core\\'),
        ));
        $this->assertSame([], $core, 'notify on its own loads no class of preflow/core');
    }
}
