<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\HiddenAiPackage;

/**
 * Settings → Roles without preflow/ai: a role's permission matrix has no AI group (nothing to
 * grant for features that are not there); with the package the AI permissions are offered.
 */
final class RolesWithoutAiTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function roleForm(): string
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['page.*']);
        $this->as('admin');
        $res = $this->send('GET', '/folio/rbac_role/r-editor/edit');
        $this->assertSame(200, $res->getStatusCode());

        return (string) $res->getBody();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_the_ai_package_the_matrix_offers_no_ai_permissions(): void
    {
        HiddenAiPackage::hide();
        $form = $this->roleForm();

        foreach (['ai.translate', 'ai.draft', 'ai.settings'] as $permission) {
            $this->assertStringNotContainsString('value="' . $permission . '"', $form, $permission);
        }
    }

    #[RunInSeparateProcess]
    public function test_with_the_ai_package_the_matrix_offers_them(): void
    {
        $form = $this->roleForm();

        foreach (['ai.translate', 'ai.draft', 'ai.settings'] as $permission) {
            $this->assertStringContainsString('value="' . $permission . '"', $form, $permission);
        }
    }
}
