<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\Testing\ChecksFrameBalance;

/** Validation needs no database (0.66): RuleFactory, ValidatorFactory and app/Rules/ without config/data.php. */
final class ValidationWithoutDataTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/validation_nodata_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/Rules', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'engine' => 'twig', 'key' => 'validation-nodata-key-0123456789'];\n");
        file_put_contents($this->dir . '/app/Rules/NotBelowNoData.php', <<<'PHP'
        <?php
        namespace App\Rules;

        use Preflow\Validation\RuleAlias;
        use Preflow\Validation\ValidationContext;
        use Preflow\Validation\ValidationRule;
        use Preflow\Validation\Message;

        #[RuleAlias('not_below_nodata')]
        final class NotBelowNoData implements ValidationRule
        {
            public function __construct(private readonly string $field = '') {}

            public function validate(mixed $value, ValidationContext $context): true|string|Message
            {
                return (float) $value >= (float) ($context->data[$this->field] ?? 0) ? true : 'Too low.';
            }
        }
        PHP);
        require_once $this->dir . '/app/Rules/NotBelowNoData.php';
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_validators_and_app_rules_are_bound_without_config_data(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $this->assertFalse($app->hasConfigFile('data'));
        $this->assertTrue($c->bound(RuleFactory::class));
        $this->assertTrue($c->bound(ValidatorFactory::class));
        $this->assertFalse($c->bound(\Preflow\Data\DataManager::class), 'still no data layer');

        $validator = $c->get(ValidatorFactory::class)->make(
            ['to' => ['required', 'not_below_nodata:from']],
            ['from' => '50', 'to' => '40'],
        );
        $result = $validator->validate();

        $this->assertFalse($result->passes());
        $this->assertSame(['Too low.'], $result->errors()['to'] ?? null);
    }

    /** @since 0.84.2 a site's own form answers in the visitor's language on a public page, with no wiring */
    #[RunInSeparateProcess]
    public function test_a_public_page_validates_in_the_visitors_language_with_no_setup(): void
    {
        // The package's own texts reach an app through its installed.json; the fixture has none, so it ships them as its lang/.
        mkdir($this->dir . '/lang/de', 0777, true);
        copy(dirname(__DIR__, 2) . '/validation/lang/de/validation.php', $this->dir . '/lang/de/validation.php');
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['locales' => ['en', 'de'], 'default' => 'en'];\n");
        $app = Application::create($this->dir);
        $app->boot();
        $factory = $app->container()->get(ValidatorFactory::class);
        $site = $app->site();
        $required = static fn (): array => $factory->make(['name' => ['required']], [])->validate()->fieldErrors('name');
        $visit = \Preflow\Core\Context\RenderContext::fromRequest(new \Nyholm\Psr7\ServerRequest('GET', '/de/contact'), $site, new \Preflow\Core\Context\SystemClock());

        $german = \Preflow\Core\Context\Contexts::run($visit->in('de'), $required);
        $english = \Preflow\Core\Context\Contexts::run($visit->in('en'), $required);

        $this->assertSame(['Dieses Feld ist erforderlich.'], $german);
        $this->assertSame(['This field is required.'], $english);
    }

    /** @since 0.84.2 a factory bound before boot() is kept: the opt-out for the English messages */
    #[RunInSeparateProcess]
    public function test_a_factory_bound_before_boot_is_kept(): void
    {
        mkdir($this->dir . '/lang/de', 0777, true);
        copy(dirname(__DIR__, 2) . '/validation/lang/de/validation.php', $this->dir . '/lang/de/validation.php');
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['locales' => ['en', 'de'], 'default' => 'en'];\n");
        $app = Application::create($this->dir);
        $english = new ValidatorFactory(new RuleFactory());
        $app->container()->instance(ValidatorFactory::class, $english);
        $app->boot();

        $this->assertSame($english, $app->container()->get(ValidatorFactory::class));
        $visit = \Preflow\Core\Context\RenderContext::fromRequest(new \Nyholm\Psr7\ServerRequest('GET', '/de/contact'), $app->site(), new \Preflow\Core\Context\SystemClock());
        $errors = \Preflow\Core\Context\Contexts::run($visit->in('de'), static fn (): array => $english->make(['name' => ['required']], [])->validate()->fieldErrors('name'));
        $this->assertSame(['This field is required.'], $errors);
    }
}
