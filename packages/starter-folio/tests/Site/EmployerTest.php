<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Jobs\JobWriter;
use App\Models\Job;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\Publisher;
use Tests\Support\StarterApp;

/** The employer area: sign-in, posting a draft, editing, publishing. */
final class EmployerTest extends TestCase
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

    /** @return array<string, string> */
    private function input(array $override = []): array
    {
        return $override + [
            'title' => 'Platform Wrangler',
            'place' => 'Berlin',
            'employmentType' => 'full_time',
            'remote' => 'hybrid',
            'salaryFrom' => '60000',
            'salaryTo' => '75000',
            'description' => 'You build the services behind our job board, from the API down to the queries.',
        ];
    }

    /** @return string the CSRF token of the form on $path */
    private function token(string $path, array $cookies): string
    {
        $html = $this->get($path, ['Cookie' => $this->cookieHeader($cookies)])->body();
        $this->assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $match), 'a form with a token on ' . $path);

        return html_entity_decode($match[1]);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed> $body
     */
    private function postAs(array $cookies, string $uri, array $body, string $tokenFrom = '/employer/jobs/new'): \Preflow\Testing\TestResponse
    {
        $body += ['_csrf_token' => $this->token($tokenFrom, $cookies)];

        return $this->post($uri, $body, ['Cookie' => $this->cookieHeader($cookies)]);
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    private function draftOf(string $title): Job
    {
        $job = $this->data()->query(Job::class)->where('title', $title)->first();
        $this->assertInstanceOf(Job::class, $job);

        return $job;
    }

    /** @param array<string, string> $old what the controller passes as `old` */
    private function renderForm(array $old): string
    {
        $response = $this->container()->get(\Preflow\View\PageResponder::class)->render('_views/employer/form.twig', [
            'job' => new Job(),
            'action' => '/employer/jobs',
            'old' => $old,
            'errors' => null,
        ]);

        return (string) $response->getBody();
    }

    public function test_the_form_shows_input_kept_after_an_expired_session(): void
    {
        // The fields a form sent back after an expired session had (OldInput): the form builder
        // shows them unless the page passes input of its own.
        $this->container()->get(\Preflow\Form\FormExtensionProvider::class)->setOldInput(['title' => 'Kept title']);

        $this->assertStringContainsString('value="Kept title"', $this->renderForm([]));
        $this->assertStringContainsString('value="Own title"', $this->renderForm(['title' => 'Own title']));
    }

    public function test_employer_area_redirects_to_login_with_next(): void
    {
        $response = $this->get('/employer/jobs/new');

        $this->assertSame(302, $response->status());
        $response->assertRedirect('/employer/login?next=%2Femployer%2Fjobs%2Fnew');
        $this->get('/employer')->assertRedirect('/employer/login?next=%2Femployer');
    }

    public function test_sign_in_page_is_a_labelled_card_with_the_demo_hint_while_developing(): void
    {
        $page = $this->get('/employer/login');

        $page->assertOk();
        $page->assertSee('class="login-card"');
        $page->assertSee('<label for=');
        $page->assertSee('autocomplete="current-password"');
        $page->assertSee('class="login-hint"');
        $page->assertSee('<code>employer@example.com</code>');
        $page->assertSee('<code>demo-employer</code>');
    }

    public function test_no_demo_hint_once_the_demo_account_is_gone(): void
    {
        $data = $this->container()->get(DataManager::class);
        $user = $data->query(User::class)->where('email', 'employer@example.com')->first();
        $this->assertInstanceOf(User::class, $user);
        $data->delete(User::class, (string) $user->uuid);

        $this->get('/employer/login')->assertNotSee('class="login-hint"');
    }

    public function test_wrong_password_shows_error(): void
    {
        $cookies = $this->cookiesOf($this->send('GET', '/employer/login'));
        $token = $this->token('/employer/login', $cookies);
        $response = $this->post('/employer/login', ['email' => 'employer@example.com', 'password' => 'wrong', '_csrf_token' => $token], ['Cookie' => $this->cookieHeader($cookies)]);

        $this->assertSame(422, $response->status());
        $response->assertSee('Email or password is wrong.');
        $response->assertSee('value="employer@example.com"');
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/employer/login?next=%2Femployer');
    }

    public function test_sign_in_goes_to_next_or_the_employer_page(): void
    {
        $cookies = $this->signInEmployer();
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertOk()->assertSee('Northwind Labs');

        $login = $this->send('GET', '/employer/login?next=%2Femployer%2Fjobs%2Fnew');
        $cookies = $this->cookiesOf($login);
        $this->assertStringContainsString('name="next" value="/employer/jobs/new"', (string) $login->getBody());
        $token = $this->token('/employer/login', $cookies);
        $response = $this->post('/employer/login', ['email' => 'employer@example.com', 'password' => 'demo-employer', 'next' => '/employer/jobs/new', '_csrf_token' => $token], ['Cookie' => $this->cookieHeader($cookies)]);
        $response->assertRedirect('/employer/jobs/new');
    }

    public function test_the_email_signs_in_in_any_case(): void
    {
        // employer:create stores the email in lower case; people type it as they like.
        $cookies = $this->signInEmployer(' Employer@Example.COM ');

        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertOk()->assertSee('Northwind Labs');
    }

    public function test_open_redirect_is_refused(): void
    {
        foreach (['//evil.example', 'https://evil.example/employer', '/employer@evil.example', '/employerx', '/elsewhere', '/employer/\\evil', "/employer\n"] as $next) {
            $cookies = $this->cookiesOf($this->send('GET', '/employer/login'));
            $token = $this->token('/employer/login', $cookies);
            $response = $this->post('/employer/login', ['email' => 'employer@example.com', 'password' => 'demo-employer', 'next' => $next, '_csrf_token' => $token], ['Cookie' => $this->cookieHeader($cookies)]);

            $response->assertRedirect('/employer');
            $this->assertSame(302, $response->status(), $next);
        }
    }

    public function test_flow_sign_in_post_draft_publish(): void
    {
        $cookies = $this->signInEmployer();

        $created = $this->postAs($cookies, '/employer/jobs', $this->input());
        $this->assertSame(302, $created->status());
        $created->assertRedirect('/employer');
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertSee('Draft saved.')->assertSee('Platform Wrangler')->assertSee('Draft');

        $job = $this->draftOf('Platform Wrangler');
        $this->assertSame('draft', $this->container()->get(JobWriter::class)->state($job));
        $this->get('/jobs/' . $job->slug)->assertNotFound();
        $this->assertStringNotContainsString($job->slug, $this->get('/jobs?q=Wrangler')->body());

        // The seeded employer has the employer role, which may publish: the button is there.
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertSee('/employer/jobs/' . $job->uuid . '/publish"');
        $published = $this->postAs($cookies, '/employer/jobs/' . $job->uuid . '/publish', [], '/employer');
        $this->assertSame(302, $published->status());
        $published->assertRedirect('/employer');

        $this->get('/jobs/' . $job->slug)->assertOk()->assertSee('Platform Wrangler');
        $this->assertStringContainsString('/jobs/' . $job->slug, $this->get('/jobs?q=Wrangler')->body());

        $this->postAs($cookies, '/employer/jobs/' . $job->uuid . '/unpublish', [], '/employer');
        $this->get('/jobs/' . $job->slug)->assertNotFound();
    }

    public function test_an_account_without_the_employer_role_posts_drafts_only(): void
    {
        $user = $this->data()->query(User::class)->where('email', 'employer@example.com')->first();
        $user->roles = [];
        $this->data()->save($user);
        $cookies = $this->signInEmployer();
        $this->postAs($cookies, '/employer/jobs', $this->input());
        $job = $this->draftOf('Platform Wrangler');

        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertSee('Platform Wrangler')->assertNotSee('/publish"');
        $refused = $this->postAs($cookies, '/employer/jobs/' . $job->uuid . '/publish', [], '/employer');

        // Back to the list with the reason (a production 403 page would only say "Forbidden").
        $refused->assertRedirect('/employer');
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])
            ->assertSee('Your account may post drafts; publishing needs a full employer account.')
            ->assertSee('notice--error');
        $this->assertSame('draft', $this->container()->get(JobWriter::class)->state($job));
    }

    public function test_edit_updates_a_job(): void
    {
        $cookies = $this->signInEmployer();
        $this->postAs($cookies, '/employer/jobs', $this->input());
        $job = $this->draftOf('Platform Wrangler');

        $form = $this->get('/employer/jobs/' . $job->uuid . '/edit', ['Cookie' => $this->cookieHeader($cookies)]);
        $form->assertOk()->assertSee('value="Platform Wrangler"')->assertSee('Full time');

        $response = $this->postAs($cookies, '/employer/jobs/' . $job->uuid, $this->input(['title' => 'Platform Sage']), '/employer/jobs/' . $job->uuid . '/edit');
        $this->assertSame(302, $response->status());
        $this->assertSame('Platform Sage', $this->draftOf('Platform Sage')->title);
    }

    public function test_validation_errors_rerender_with_422(): void
    {
        $cookies = $this->signInEmployer();

        $response = $this->postAs($cookies, '/employer/jobs', $this->input(['title' => '', 'place' => 'Hamburg', 'description' => 'too short']));

        $this->assertSame(422, $response->status());
        $response->assertSee('value="Hamburg"');
        $response->assertSee('too short');
        $response->assertSee('This field is required.');
        $response->assertSee('Must be at least 30 characters.');
        $this->assertNull($this->data()->query(Job::class)->where('place', 'Hamburg')->first());

        $bad = $this->postAs($cookies, '/employer/jobs', $this->input(['salaryFrom' => 'lots']));
        $this->assertSame(422, $bad->status());
    }

    public function test_publishing_a_draft_that_breaks_a_rule_shows_the_form_with_errors(): void
    {
        // Staff can save a half-finished draft in Folio (drafts skip the rules); publishing checks them.
        $owner = $this->data()->query(User::class)->where('email', 'employer@example.com')->first();
        $draft = DynamicRecord::fromArray($this->data()->getTypeRegistry()->get('job'), [
            'uuid' => md5('employer-test-half-finished'), 'title' => 'Half Finished', 'slug' => 'half-finished',
            'company' => $owner->company, 'place' => 'Berlin', 'employmentType' => 'full_time', 'remote' => 'no',
            'description' => 'too short',
        ]);
        $this->container()->get(Publisher::class)->saveDraft($draft, null);
        $cookies = $this->signInEmployer();

        $response = $this->postAs($cookies, '/employer/jobs/' . $draft->getId() . '/publish', [], '/employer');

        $this->assertSame(422, $response->status());
        $response->assertSee('Please fix the errors below.');
        $response->assertSee('Must be at least 30 characters.');
        $response->assertSee('action="/employer/jobs/' . $draft->getId() . '"');
        $this->assertNull($this->container()->get(Publisher::class)->snapshot('job', (string) $draft->getId()));
    }

    public function test_post_without_csrf_is_refused(): void
    {
        $cookies = $this->signInEmployer();

        $response = $this->post('/employer/jobs', $this->input(), ['Cookie' => $this->cookieHeader($cookies)]);

        $this->assertSame(403, $response->status());
        $this->assertNull($this->data()->query(Job::class)->where('title', 'Platform Wrangler')->first());
    }

    public function test_other_company_job_is_404(): void
    {
        $data = $this->data();
        $mine = $data->query(User::class)->where('email', 'employer@example.com')->first();
        $other = $data->query(Job::class)->where('company', '!=', $mine->company)->first();
        $this->assertInstanceOf(Job::class, $other);
        $title = $other->title;
        $cookies = $this->signInEmployer();

        $this->get('/employer/jobs/' . $other->uuid . '/edit', ['Cookie' => $this->cookieHeader($cookies)])->assertNotFound();
        $this->postAs($cookies, '/employer/jobs/' . $other->uuid, $this->input())->assertNotFound();
        $this->postAs($cookies, '/employer/jobs/' . $other->uuid . '/publish', [])->assertNotFound();
        $this->postAs($cookies, '/employer/jobs/' . $other->uuid . '/unpublish', [])->assertNotFound();
        $this->get('/employer/jobs/nothing-like-it/edit', ['Cookie' => $this->cookieHeader($cookies)])->assertNotFound();

        $this->assertSame($title, $this->data()->find(Job::class, $other->uuid)->title);
    }

    public function test_logout(): void
    {
        $cookies = $this->signInEmployer();

        $response = $this->postAs($cookies, '/employer/logout', [], '/employer');

        $this->assertSame(302, $response->status());
        $response->assertRedirect('/');
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/employer/login?next=%2Femployer');
    }
}
