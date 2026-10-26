<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Users\UserPreferences;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * Validation messages in Folio's forms (Ruling 2): an admin request validates with the
 * translator, in the user's interface language; the site's own forms (no admin request, no
 * translator) keep the English messages, also after an admin request in the same process.
 */
final class ValidationTranslationTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Posts',
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']]],
        ]));
        $this->flow = $this->app();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    #[RunInSeparateProcess]
    public function test_a_required_field_left_empty_says_so_in_german_and_the_frontend_stays_english(): void
    {
        $ada = $this->makeStaff();
        $this->flow->container()->get(UserPreferences::class)->setLocale((string) $ada->getId(), 'de');
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));

        $response = $this->post('/folio/post', ['title' => '']);
        $body = (string) $response->getBody();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Dieses Feld ist erforderlich.', $body);
        $this->assertStringNotContainsString('This field is required.', $body);

        // A frontend form: the shared validator, no admin request around it.
        $factory = $this->flow->container()->get(ValidatorFactory::class);
        $this->assertSame(['This field is required.'], $factory->make(['name' => ['required']], [])->validate()->fieldErrors('name'));
        try {
            $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('post'), ['uuid' => 'p1', 'title' => '']));
            $this->fail('saved without a title');
        } catch (ValidationException $e) {
            $this->assertSame('This field is required.', $e->result()->firstError('title'));
        }
    }
}
