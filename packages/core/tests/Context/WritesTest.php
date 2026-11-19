<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Context\Writes;
use Preflow\Core\Context\WritesRefused;
use Preflow\Testing\ContextFactory;

/** Writes::allowed(): Allow and no frame write, Skip records, Refuse throws (log writes skipped); WritesRefused is a 403. */
final class WritesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        if ($this->dir === '') {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_allowed_without_a_frame_and_under_allow(): void
    {
        $this->assertTrue(Writes::allowed('default', 'items', '1', 'save'));
        $frame = null;
        $allowed = Contexts::run(ContextFactory::forTest(), function () use (&$frame): bool {
            $frame = Contexts::frame();

            return Writes::allowed('default', 'items', '1', 'save');
        });
        $this->assertTrue($allowed);
        $this->assertSame([], $frame->skippedWrites());
    }

    public function test_skip_records_the_write_and_refuse_throws_except_for_logs(): void
    {
        $frame = null;
        $answers = Contexts::run(ContextFactory::forTest()->skippingWrites(), function () use (&$frame): array {
            $frame = Contexts::frame();

            return [Writes::allowed('default', 'items', '1', 'save'), Writes::allowed('redirects', 'not-found', '/x', 'log')];
        });
        $this->assertSame([false, false], $answers);
        $this->assertEquals([
            new SkippedWrite('default', 'items', '1', 'save'),
            new SkippedWrite('redirects', 'not-found', '/x', 'log'),
        ], $frame->skippedWrites());

        $frame = null;
        try {
            Contexts::run(ContextFactory::forTest()->refusingWrites(), function () use (&$frame): void {
                $frame = Contexts::frame();
                $this->assertFalse(Writes::allowed('redirects', 'redirect-hits', 'a', 'log'), 'a log write under Refuse is skipped');
                Writes::allowed('default', 'items', null, 'delete');
            });
            $this->fail('refused');
        } catch (WritesRefused $e) {
            $this->assertEquals(new SkippedWrite('default', 'items', null, 'delete'), $e->write);
        }
        $this->assertEquals([new SkippedWrite('redirects', 'redirect-hits', 'a', 'log')], $frame->skippedWrites());
    }

    public function test_writes_refused_is_answered_403(): void
    {
        $this->dir = sys_get_temp_dir() . '/writes_refused_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        mkdir($this->dir . '/app/Controllers', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'writes-refused-test-key-0123456789','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>home</body></html>');
        file_put_contents($this->dir . '/app/Controllers/WritesRefusedNoteController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Nyholm\Psr7\Response;
            use Preflow\Core\Http\Csrf\CsrfExempt;
            use Preflow\Data\DataManager;
            use Preflow\Routing\Attributes\Post;
            use Preflow\Routing\Attributes\Route;

            #[Route('/notes')]
            final class WritesRefusedNoteController
            {
                public function __construct(private readonly DataManager $dm) {}

                #[Post('/')]
                #[CsrfExempt('test route')]
                public function create(): Response
                {
                    $note = new \Preflow\Core\Tests\Context\WritesRefusedNote();
                    $note->body = 'posted';
                    $this->dm->save($note);

                    return new Response(201, [], 'saved');
                }
            }
            PHP);
        $dir = $this->dir;
        spl_autoload_register(static function (string $class) use ($dir): void {
            if ($class === 'App\\Controllers\\WritesRefusedNoteController') {
                require $dir . '/app/Controllers/WritesRefusedNoteController.php';
            }
        });

        $app = Application::create($this->dir);
        $app->boot();
        $post = (new Psr17Factory())->createServerRequest('POST', 'http://localhost/notes');
        $clock = new FrozenClock(new \DateTimeImmutable(ContextFactory::NOW));

        $refused = $app->render(RenderContext::fromRequest($post, $app->site(), $clock)->refusingWrites(), $post)->response;

        $this->assertSame(403, $refused->getStatusCode());
        $this->assertStringContainsString('This preview does not change data.', (string) $refused->getBody());
        $this->assertSame([], glob($this->dir . '/storage/data/notes/*') ?: [], 'nothing saved');

        $saved = $app->render(RenderContext::fromRequest($post, $app->site(), $clock), $post)->response;
        $this->assertSame(201, $saved->getStatusCode(), 'the same POST saves outside a preview');
        $this->assertCount(1, glob($this->dir . '/storage/data/notes/*') ?: []);
    }
}

#[\Preflow\Data\Attributes\Entity(table: 'notes', storage: 'default')]
final class WritesRefusedNote extends \Preflow\Data\Model
{
    #[\Preflow\Data\Attributes\Id]
    public string $uuid = '';

    #[\Preflow\Data\Attributes\Field]
    public string $body = '';
}
