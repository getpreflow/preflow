<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\Job;
use App\Models\User;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\PersonalAccessToken;
use Preflow\Data\DataManager;
use Preflow\Publishing\Publisher;
use Preflow\Testing\TestResponse;
use Tests\Support\StarterApp;

/** The token-protected write side: an employer's own jobs, through publishing's RecordWriter as Folio and the employer area save. */
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
            'employmentType' => 'full_time',
            'remote' => 'hybrid',
            'salaryFrom' => 60000,
            'salaryTo' => 75000,
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
        $id = $response->json('data._id');
        $response->assertHeader('Location', '/api/jobs/' . $id);
        // The owner sees the publish state; the server sets the company (the token's), the slug and the dates.
        $this->assertSame('draft', $response->json('data._status'));
        $this->assertSame($this->data()->query(User::class)->where('email', 'employer@example.com')->first()->company, $response->json('data.company'));
        $this->assertSame('api-platform-engineer', $response->json('data.slug'));
        $this->assertSame(60000, $response->json('data.salaryFrom'));
        $this->assertSame(75000, $response->json('data.salaryTo'));
        $this->assertNull($response->json('data.postedOn'));

        // A draft is not public.
        $this->assertSame(404, $this->json('GET', '/api/jobs/' . $id)->status());
    }

    public function test_create_published_appears_in_public_list(): void
    {
        $response = $this->json('POST', '/api/jobs', $this->body(['status' => 'published']), $this->bearer());

        $this->assertSame(201, $response->status());
        $this->assertSame('published', $response->json('data._status'));
        $this->assertNotNull($response->json('data.postedOn'));

        $list = $this->json('GET', '/api/jobs');
        $this->assertSame(35, $list->json('meta.total'));
        $this->assertSame($response->json('data._id'), $list->json('data.0._id'));
        $this->assertArrayNotHasKey('_status', $list->json('data.0'));
    }

    public function test_publishing_needs_the_job_publish_permission(): void
    {
        // An account without the employer role (a trial account, say) posts drafts only.
        $user = $this->data()->query(User::class)->where('email', 'employer@example.com')->first();
        $user->roles = [];
        $this->data()->save($user);
        $headers = $this->bearer();
        $total = $this->json('GET', '/api/jobs')->json('meta.total');

        $created = $this->json('POST', '/api/jobs', $this->body(['status' => 'published']), $headers);
        $this->assertSame(403, $created->status());
        $this->assertArrayHasKey('message', $created->json());

        $id = $this->json('POST', '/api/jobs', $this->body(['title' => 'Draft Only Engineer']), $headers)->json('data._id');
        $this->assertNotNull($id, 'a draft still goes through');
        $patched = $this->json('PATCH', '/api/jobs/' . $id, ['title' => 'Renamed Engineer', 'status' => 'published'], $headers);
        $this->assertSame(403, $patched->status());

        $this->assertSame($total, $this->json('GET', '/api/jobs')->json('meta.total'), 'nothing was published');
        $this->assertSame(0, $this->data()->query(Job::class)->where('title', 'API Platform Engineer')->get()->total(), 'the refused create saved nothing');
        $this->assertSame('Draft Only Engineer', $this->data()->find(Job::class, $id)->title, 'the refused update saved nothing');
    }

    public function test_validation_422_is_keyed_by_the_field(): void
    {
        $response = $this->json('POST', '/api/jobs', ['title' => '', 'employmentType' => 'bogus', 'remote' => 'hybrid', 'place' => 'Berlin', 'description' => 'x'], $this->bearer());

        $response->assertJsonValidationErrors('title', 'employmentType', 'description');

        $salary = $this->json('POST', '/api/jobs', $this->body(['salaryFrom' => 'lots']), $this->bearer());
        $salary->assertJsonValidationErrors('salaryFrom');
        $range = $this->json('POST', '/api/jobs', $this->body(['salaryFrom' => 80000, 'salaryTo' => 70000]), $this->bearer());
        $range->assertJsonValidationErrors('salaryTo');

        // What the server sets is refused, not dropped; so is a key that is no field.
        $this->json('POST', '/api/jobs', $this->body(['company' => str_repeat('0', 32), 'postedOn' => '2026-01-01']), $this->bearer())
            ->assertJsonValidationErrors('company', 'postedOn');
        $this->json('POST', '/api/jobs', $this->body(['_id' => str_repeat('0', 32)]), $this->bearer())->assertJsonValidationErrors('_id');
        $this->json('POST', '/api/jobs', $this->body(['salary' => 1]), $this->bearer())->assertJsonValidationErrors('salary');
        $this->assertSame(0, $this->data()->query(Job::class)->where('title', 'API Platform Engineer')->get()->total(), 'nothing was saved');

        $status = $this->json('POST', '/api/jobs', $this->body(['status' => 'archived']), $this->bearer());
        $status->assertJsonValidationErrors('status');
    }

    public function test_patch_own_job(): void
    {
        $headers = $this->bearer();
        $id = $this->json('POST', '/api/jobs', $this->body(), $headers)->json('data._id');

        $patched = $this->json('PATCH', '/api/jobs/' . $id, ['title' => 'Renamed Engineer'], $headers);
        $patched->assertOk();
        $this->assertSame('Renamed Engineer', $patched->json('data.title'));
        $this->assertSame('Berlin', $patched->json('data.place'), 'fields not sent stay as they were');

        $published = $this->json('PATCH', '/api/jobs/' . $id, ['status' => 'published'], $headers);
        $this->assertSame('published', $published->json('data._status'));
        $this->assertSame(200, $this->json('GET', '/api/jobs/' . $id)->status());

        // An edit without "status" waits in the working copy: the public still sees the published one.
        $changed = $this->json('PATCH', '/api/jobs/' . $id, ['title' => 'Edited Engineer'], $headers);
        $this->assertSame('changed', $changed->json('data._status'));
        $this->assertSame('Renamed Engineer', $this->json('GET', '/api/jobs/' . $id)->json('data.title'));

        $back = $this->json('PATCH', '/api/jobs/' . $id, ['status' => 'draft'], $headers);
        $this->assertSame('draft', $back->json('data._status'));
        $this->assertSame(404, $this->json('GET', '/api/jobs/' . $id)->status());

        $this->json('PATCH', '/api/jobs/' . $id, ['employmentType' => 'nope'], $headers)
            ->assertJsonValidationErrors('employmentType');
        $this->json('PATCH', '/api/jobs/' . $id, ['status' => 'archived'], $headers)
            ->assertJsonValidationErrors('status');
        $this->assertSame(404, $this->json('PATCH', '/api/jobs/' . str_repeat('0', 32), ['title' => 'x'], $headers)->status());
    }

    public function test_publishing_a_draft_that_breaks_a_rule_is_422_keyed_by_the_field(): void
    {
        $headers = $this->bearer();
        $id = $this->json('POST', '/api/jobs', $this->body(), $headers)->json('data._id');
        // Staff can save a draft in Folio that breaks a rule (drafts skip them); publishing checks them.
        $draft = $this->data()->findType('job', $id);
        $draft->set('employmentType', 'freelance');
        $this->container()->get(Publisher::class)->saveDraft($draft, null);

        $response = $this->json('PATCH', '/api/jobs/' . $id, ['status' => 'published'], $headers);

        $response->assertJsonValidationErrors('employmentType');
        $this->assertSame(404, $this->json('GET', '/api/jobs/' . $id)->status());
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
        $id = $this->json('POST', '/api/jobs', $this->body(), $headers)->json('data._id');

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
