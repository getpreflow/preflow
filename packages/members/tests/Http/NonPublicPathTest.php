<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;

/** The members area is not a public page: redirects and the not-found list leave it alone. */
final class NonPublicPathTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_members_path_is_not_public(): void
    {
        $this->flowSite(['path' => '/konto/'], ['en', 'de']);
        $paths = $this->flow->container()->get(NonPublicPaths::class);

        $this->assertTrue($paths->matches('/konto'));
        $this->assertTrue($paths->matches('/konto/login'));
        $this->assertFalse($paths->matches('/kontakt'));
    }
}
