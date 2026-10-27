<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Members\Admission\OwnerApproval;
use Preflow\Members\Tests\Fixtures\ComposedNameAdmission;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;

/** An admission that composes the name (ComposesName): no name field, the stored name is the admission's. */
final class ComposedNameTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    private const string FIELDS = '{% block admission_fields %}<input name="first_name"><input name="last_name">{% endblock %}';

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_the_form_has_no_name_field_and_the_stored_name_is_the_admissions(): void
    {
        $this->flowSite(['admission' => ComposedNameAdmission::class], files: ['resources/members/register.twig' => "{% extends '@members-package/register.twig' %}" . self::FIELDS]);

        $page = $this->html('/members/register');
        $this->assertNull(Dom::first($page, '//input[@name="name"]'), 'no name field');
        $this->assertNotNull(Dom::first($page, '//input[@name="first_name"]'));
        $this->assertNotNull(Dom::first($page, '//input[@name="email"]'));

        $sent = $this->post('/members/register', ['first_name' => ' Ada ', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame(200, $sent->getStatusCode());
        $this->assertSame('Ada Lovelace', $this->member('ada@example.com')->get('name'));
        $this->assertSame('Ada Lovelace', ComposedNameAdmission::$seen[array_key_last(ComposedNameAdmission::$seen)]['name'], 'the admission validates with the composed name');
    }

    #[RunInSeparateProcess]
    public function test_a_posted_name_is_ignored_and_an_empty_composition_is_an_error(): void
    {
        $this->flowSite(['admission' => ComposedNameAdmission::class], files: ['resources/members/register.twig' => "{% extends '@members-package/register.twig' %}" . self::FIELDS]);

        $this->post('/members/register', ['name' => 'Mallory', 'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame('Grace Hopper', $this->member('grace@example.com')->get('name'));

        $none = $this->post('/members/register', ['name' => 'Mallory', 'email' => 'x@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame(422, $none->getStatusCode());
        $this->assertNull($this->flow->container()->get(\Preflow\Members\Members::class)->findByEmail('x@example.com'));
    }

    #[RunInSeparateProcess]
    public function test_without_the_interface_the_form_is_as_before(): void
    {
        $this->flowSite(['admission' => OwnerApproval::class]);

        $this->assertNotNull(Dom::first($this->html('/members/register'), '//input[@name="name"]'));
        $missing = $this->post('/members/register', ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame(422, $missing->getStatusCode());
        $this->assertNotNull(Dom::first((string) $missing->getBody(), '//div[contains(@class,"field--error")]//input[@name="name"]'));
        $this->post('/members/register', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame('Ada Lovelace', $this->member('ada@example.com')->get('name'));
    }
}
