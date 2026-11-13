<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\EnvFieldType;

/** The env field (board LibFields): key icon, mono name, "set" / "not set"; never the value. */
final class EnvFieldTypeTest extends TestCase
{
    private const string NAME = 'FOLIO_ENV_FIELD_TEST_KEY';
    private const string SECRET = 'sk-very-secret-value-4711';

    protected function tearDown(): void
    {
        putenv(self::NAME);
    }

    public function test_a_set_variable_shows_set_and_never_its_value(): void
    {
        putenv(self::NAME . '=' . self::SECRET);
        $html = (new EnvFieldType())->renderEditor(new FieldContext(name: 'api_key', label: 'API key', help: 'From the environment.', config: ['env' => self::NAME]));

        $this->assertMatchesRegularExpression(
            '~<div class="folio-env"><svg class="folio-icon"[^>]*>.*?</svg><code class="folio-env-name">' . self::NAME . '</code>'
            . '<span class="folio-env-state is-set"><span class="folio-env-dot" aria-hidden="true"></span>set</span></div>~s',
            $html,
        );
        $this->assertStringContainsString('<span class="form-label">API key</span>', $html);
        $this->assertStringNotContainsString(self::SECRET, $html, 'not in text, an attribute or a title');
        foreach (['sk-very', 'secret-value', '4711', base64_encode(self::SECRET), md5(self::SECRET)] as $part) {
            $this->assertStringNotContainsString($part, $html);
        }
        $this->assertStringNotContainsString('<input', $html, 'nothing is posted');
    }

    public function test_an_unset_variable_shows_not_set(): void
    {
        putenv(self::NAME);
        $html = (new EnvFieldType())->renderEditor(new FieldContext(name: 'api_key', config: ['env' => self::NAME]));
        $this->assertStringContainsString('<span class="folio-env-state is-missing"><span class="folio-env-dot" aria-hidden="true"></span>not set</span>', $html);
    }
}
