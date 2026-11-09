<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\Job;
use App\Models\User;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\PersonalAccessToken;
use Preflow\Data\DataManager;
use Preflow\Testing\TestResponse;
use Tests\Support\StarterApp;

/** The token-protected write side: an employer's own jobs, through the same JobWriter as the employer area. */
final class WriteApiTest extends TestCase
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

    /** @return array{Authorization: string} */
    private function bearer(string $email = 'employer@example.com'): array
    {
        $user = $this->data()->query(User::class)->where('email', $email)->first();
        $this->assertInstanceOf(User::class, $user);

        $plain = PersonalAccessToken::generatePlainToken();
        $token = new PersonalAccessToken();
        $token->uuid = bin2hex(random_bytes(16));
        $token->tokenHash = PersonalAccessToken::hashToken($plain);
        $token->userId = $user->getAuthId();
        $token->name = 'test';
        $token->createdAt = date('c');
        $this->data()->save($token);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    /** @return array<string, mixed> */
    private function body(array $override = []): array
    {
        return $override + [
            'title' => 'API Platform Engineer',
            'place' => 'Berlin',
            'employment_type' => 'full_time',
            'remote' => 'hybrid',
            'salary_from' => 60000,
            'salary_to' => 75000,
            'description' => 'You build the services behind our job board, from the API down to the queries.',
        ];
    }

    public function test_create_without_token_is_401_json_with_www_authenticate(): void
    {
        $response = $this->json('POST', '/api/jobs', $this->body());

        $this->assertSame(401, $response->status());
        $this->assertArrayHasKey('message', $response->json());
        $response->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_session_cookie_does_not_pass_token_routes(): void
    {
        $cookies = $this->signInEmployer();

        $response = $this->json('POST', '/api/jobs', $this->body(), ['Cookie' => $this->cookieHeader($cookies)]);

        $this->assertSame(401, $response->status());
    }

    public function test_create_draft_201_with_location(): void
    {
        $response = $this->json('POST', '/api/jobs', $this->body(), $this->bearer());

        $this->assertSame(201, $response->status());
        $id = $response->json('data.id');
        $response->assertHeader('Location', '/api/jobs/' . $id);
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame('northwind-labs', $response->json('data.company.slug'));
        $this->assertSame(['from' => 60000, 'to' => 75000, 'currency' => 'EUR'], $response->json('data.salary'));
        $this->assertNull($response->json('data.published_at'));

        // A draft is not public.
        $this->assertSame(404, $this->json('GET', '/api/jobs/' . $id)->status());
    }

    public function test_create_published_appears_in_public_list(): void
    {
        $response = $this->json('POST', '/api/jobs', $this->body(['status' => 'published']), $this->bearer());

        $this->assertSame(201, $response->status());
        $this->assertSame('published', $response->json('data.status'));
        $this->assertNotNull($response->json('data.published_at'));

        $list = $this->json('GET', '/api/jobs');
        $this->assertSame(35, $list->json('meta.total'));
        $this->assertSame($response->json('data.id'), $list->json('data.0.id'));
        $this->assertArrayNotHasKey('status', $list->json('data.0'));
    }

    public function test_validation_422_lists_snake_case_fields(): void
    {
        $response = $this->json('POST', '/api/jobs', ['title' => '', 'employment_type' => 'bogus', 'remote' => 'hybrid', 'place' => 'Berlin', 'description' => 'x'], $this->bearer());

        $response->assertJsonValidationErrors('title', 'employment_type', 'description');
        $this->assertArrayNotHasKey('employmentType', $response->json('errors'));

        $salary = $this->json('POST', '/api/jobs', $this->body(['salary_from' => 'lots']), $this->bearer());
        $salary->assertJsonValidationErrors('salary_from');

        $status = $this->json('POST', '/api/jobs', $this->body(['status' => 'archived']), $this->bearer());
        $status->assertJsonValidationErrors('status');
    }

    public function test_patch_own_job(): void
    {
        $headers = $this->bearer();
        $id = $this->json('POST', '/api/jobs', $this->body(), $headers)->json('data.id');

        $patched = $this->json('PATCH', '/api/jobs/' . $id, ['title' => 'Renamed Engineer'], $headers);
        $patched->assertOk();
        $this->assertSame('Renamed Engineer', $patched->json('data.title'));
        $this->assertSame('Berlin', $patched->json('data.place'), 'fields not sent stay as they were');

        $published = $this->json('PATCH', '/api/jobs/' . $id, ['status' => 'published'], $headers);
        $this->assertSame('published', $published->json('data.status'));
        $this->assertSame(200, $this->json('GET', '/api/jobs/' . $id)->status());

        $back = $this->json('PATCH', '/api/jobs/' . $id, ['status' => 'draft'], $headers);
        $this->assertSame('draft', $back->json('data.status'));
        $this->assertSame(404, $this->json('GET', '/api/jobs/' . $id)->status());

        $this->json('PATCH', '/api/jobs/' . $id, ['employment_type' => 'nope'], $headers)
            ->assertJsonValidationErrors('employment_type');
        $this->assertSame(404, $this->json('PATCH', '/api/jobs/' . str_repeat('0', 32), ['title' => 'x'], $headers)->status());
    }

    public function test_patch_other_company_job_is_403(): void
    {
        $other = $this->data()->query(Job::class)->where('company', '!=', $this->data()->query(User::class)->where('email', 'employer@example.com')->first()->company)->first();
        $this->assertInstanceOf(Job::class, $other);

        $response = $this->json('PATCH', '/api/jobs/' . $other->uuid, ['title' => 'Hijacked'], $this->bearer());

        $this->assertSame(403, $response->status());
        $this->assertSame('This job belongs to another company.', $response->json('message'));
        $this->assertNotSame('Hijacked', $this->data()->find(Job::class, $other->uuid)->title);

        $this->assertSame(403, $this->json('DELETE', '/api/jobs/' . $other->uuid, null, $this->bearer())->status());
    }

    public function test_delete_own_job_204(): void
    {
        $headers = $this->bearer();
        $id = $this->json('POST', '/api/jobs', $this->body(), $headers)->json('data.id');

        $response = $this->json('DELETE', '/api/jobs/' . $id, null, $headers);

        $this->assertSame(204, $response->status());
        $this->assertSame('', $response->body());
        $this->assertNull($this->data()->find(Job::class, $id));
        $this->assertSame(404, $this->json('DELETE', '/api/jobs/' . $id, null, $headers)->status());
    }

    public function test_invalid_json_body_is_400(): void
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/api/jobs')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', $this->bearer()['Authorization'])
            ->withBody((new Psr17Factory())->createStream('{"title": '));

        $response = new TestResponse($this->app()->handle($request));

        $this->assertSame(400, $response->status());
        $this->assertArrayHasKey('message', $response->json());
    }
}
