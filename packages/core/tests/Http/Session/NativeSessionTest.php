<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Session;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Session\NativeSession;

final class NativeSessionTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function test_start_and_get_set(): void
    {
        $session = new NativeSession();
        $session->start();

        $this->assertTrue($session->isStarted());

        $session->set('user', 'alice');
        $this->assertSame('alice', $session->get('user'));
        $this->assertNull($session->get('missing'));
        $this->assertSame('default', $session->get('missing', 'default'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_has_and_remove(): void
    {
        $session = new NativeSession();
        $session->start();

        $session->set('key', 'value');
        $this->assertTrue($session->has('key'));

        $session->remove('key');
        $this->assertFalse($session->has('key'));
        $this->assertNull($session->get('key'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_regenerate_changes_id_but_keeps_data(): void
    {
        $session = new NativeSession();
        $session->start();

        $session->set('role', 'admin');
        $oldId = $session->getId();

        $session->regenerate();

        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame('admin', $session->get('role'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_invalidate_clears_data_and_changes_id(): void
    {
        $session = new NativeSession();
        $session->start();

        $session->set('secret', 'value');
        $oldId = $session->getId();

        $session->invalidate();

        $this->assertNotSame($oldId, $session->getId());
        $this->assertNull($session->get('secret'));
        $this->assertFalse($session->has('secret'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_flash_data_readable_in_same_request(): void
    {
        $session = new NativeSession();
        $session->start();

        $session->flash('notice', 'Saved successfully');

        $this->assertSame('Saved successfully', $session->getFlash('notice'));
        $this->assertNull($session->getFlash('missing'));
        $this->assertSame('nope', $session->getFlash('missing', 'nope'));
    }
}
