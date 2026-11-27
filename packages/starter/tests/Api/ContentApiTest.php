<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\Job;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\PersonalAccessToken;
use Preflow\Data\DataManager;
use Tests\Support\StarterApp;

/** The generic API (App\Controllers\Api\ContentController): the types it serves, read and written by the core classes. */
final class ContentApiTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    private function employer(): User
    {
        $user = $this->data()->query(User::class)->where('email', 'employer@example.com')->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return array{Authorization: string} */
    private function bearer(): array
    {
        $plain = PersonalAccessToken::generatePlainToken();
        $token = new PersonalAccessToken();
        $token->uuid = bin2hex(random_bytes(16));
        $token->tokenHash = PersonalAccessToken::hashToken($plain);
        $token->userId = $this->employer()->getAuthId();
        $token->name = 'test';
        $token->createdAt = date('c');
        $this->data()->save($token);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    /** The site in two languages: this copy's config/i18n.php, read by every app booted after it. */
    private function speak(string ...$locales): void
    {
        file_put_contents($this->starterRoot . '/config/i18n.php', '<?php return ' . var_export([
            'default' => $locales[0],
            'available' => $locales,
            'fallback' => $locales[0],
            'url_strategy' => 'prefix',
            'cookie' => false,
        ], true) . ';');
    }

    public function test_lists_and_reads_a_served_type(): void
    {
        $list = $this->json('GET', '/api/content/company');

        $list->assertOk();
        $this->assertSame(7, $list->json('meta.total'));
        $this->assertSame(1, $list->json('meta.page'));
        $this->assertSame(['_id', 'name', 'slug', 'place', 'website', 'summary'], array_keys($list->json('data.0')));

        $id = (string) $this->employer()->company;
        $one = $this->json('GET', '/api/content/company/' . $id);
        $one->assertOk();
        $this->assertSame($id, $one->json('data._id'));
        $this->assertSame('Northwind Labs', $one->json('data.name'));

        // What the API serves, described: the served types only, in the core's description.
        $described = $this->json('GET', '/api/content');
        $described->assertOk();
        $this->assertSame(1, $described->json('data.version'));
        $this->assertSame(['company'], array_keys($described->json('data.types')));
        $this->assertSame('string', $described->json('data.types.company.fields.name.value'));
    }

    public function test_unknown_or_unserved_type_is_404(): void
    {
        $job = $this->data()->query(Job::class)->where('status', 'published')->first();
        $this->assertInstanceOf(Job::class, $job);

        // job is a type of the site, but this controller does not serve it (TYPES).
        foreach (['/api/content/nope', '/api/content/job', '/api/content/job/' . $job->uuid, '/api/content/company/' . str_repeat('0', 32)] as $uri) {
            $response = $this->json('GET', $uri);
            $this->assertSame(404, $response->status(), $uri);
            $this->assertArrayHasKey('message', $response->json());
        }

        $headers = $this->bearer();
        $this->assertSame(404, $this->json('PATCH', '/api/content/job/' . $job->uuid, ['title' => 'Changed'], $headers)->status());
        $this->assertSame(404, $this->json('POST', '/api/content/nope', ['name' => 'x'], $headers)->status());
        $this->assertNotSame('Changed', $this->data()->find(Job::class, $job->uuid)->title);
    }

    public function test_patch_one_language_keeps_the_other(): void
    {
        $this->speak('en', 'de');
        $headers = $this->bearer();
        $id = (string) $this->employer()->company;
        $english = $this->data()->findType('company', $id)->get('summary');
        $this->assertIsString($english);

        $response = $this->json('PATCH', '/api/content/company/' . $id, ['summary' => ['de' => 'Produktentwicklung für Logistik-Software.']], $headers);

        $response->assertOk();
        $this->assertSame(['en' => $english, 'de' => 'Produktentwicklung für Logistik-Software.'], $response->json('data.summary'));
        $this->assertSame('Northwind Labs', $response->json('data.name'), 'a field the body does not name keeps its value');

        // One value with ?locale= is that language's; the other stays.
        $this->json('PATCH', '/api/content/company/' . $id . '?locale=de', ['summary' => 'Software für Logistik.'], $headers)->assertOk();
        $this->assertSame('Software für Logistik.', $this->json('GET', '/api/content/company/' . $id . '?locale=de')->json('data.summary'));
        $this->assertSame($english, $this->json('GET', '/api/content/company/' . $id . '?locale=en')->json('data.summary'));

        // A language the site does not have is refused, keyed "<field>.<locale>" as the core keys it.
        $this->json('PATCH', '/api/content/company/' . $id, ['summary' => ['fr' => 'Bonjour']], $headers)->assertJsonValidationErrors('summary.fr');
        $this->json('PATCH', '/api/content/company/' . $id . '?locale=fr', ['summary' => 'Bonjour'], $headers)->assertJsonValidationErrors('summary.fr');
    }

    public function test_errors_are_422_with_core_keys(): void
    {
        $headers = $this->bearer();
        $id = (string) $this->employer()->company;
        $uri = '/api/content/company/' . $id;

        // The write class refuses what is no field, and an id that is not this record's.
        $this->json('PATCH', $uri, ['nope' => 'x'], $headers)->assertJsonValidationErrors('nope');
        $this->json('PATCH', $uri, ['_id' => str_repeat('1', 32)], $headers)->assertJsonValidationErrors('_id');
        // The core rules refuse on save, keyed by the field.
        $this->json('PATCH', $uri, ['name' => '', 'website' => 'not an address'], $headers)->assertJsonValidationErrors('name', 'website');

        $this->assertSame('Northwind Labs', $this->data()->findType('company', $id)->get('name'), 'nothing was saved');
    }

    public function test_write_needs_a_token(): void
    {
        $id = (string) $this->employer()->company;

        $patch = $this->json('PATCH', '/api/content/company/' . $id, ['name' => 'Taken Over']);
        $this->assertSame(401, $patch->status());
        $patch->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertSame(401, $this->json('POST', '/api/content/company', ['name' => 'New Company'])->status());

        $this->assertSame('Northwind Labs', $this->data()->findType('company', $id)->get('name'));
    }

    public function test_the_controller_says_who_may_write(): void
    {
        $headers = $this->bearer();
        $own = (string) $this->employer()->company;
        $other = $this->data()->queryType('company')->where('uuid', '!=', $own)->first();
        $this->assertNotNull($other);

        // An employer changes their own company, not another's, and creates none.
        $this->json('PATCH', '/api/content/company/' . $own, ['place' => 'Hamburg'], $headers)->assertOk();
        $this->assertSame('Hamburg', $this->data()->findType('company', $own)->get('place'));
        $this->assertSame(403, $this->json('PATCH', '/api/content/company/' . $other->getId(), ['place' => 'Hamburg'], $headers)->status());
        $this->assertSame(403, $this->json('POST', '/api/content/company', ['name' => 'New Company'], $headers)->status());
        $this->assertSame(7, $this->data()->queryType('company')->get()->total());
    }
}
