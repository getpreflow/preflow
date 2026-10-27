<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Preflow\Codes\Codes;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\Schema;
use Preflow\Validation\ValidationException;

/** A code is unique within its purpose; a random code that is taken is drawn again. */
final class CodeUniqueTest extends CodesAppTestCase
{
    /** @param list<string> $draws the random codes, in order */
    private function drawing(array &$draws): Codes
    {
        $c = $this->app()->container();

        return new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            require $this->dir . '/config/codes.php',
            fn (): \DateTimeImmutable => $this->now,
            $this->dir . '/storage/locks',
            randomCode: static function () use (&$draws): string {
                return array_shift($draws) ?? throw new \LogicException('no more codes');
            },
        );
    }

    public function testIssueRetriesDuplicateCode(): void
    {
        $taken = $this->codes()->issue('q');
        $draws = [$taken, 'fresh-code-1234567890a'];

        $code = $this->drawing($draws)->issue('q');

        $this->assertSame('fresh-code-1234567890a', $code);
        $this->assertNotSame($taken, $code);
        $this->assertSame([], $draws);
    }

    public function testIssueGivesUpWhenEveryDrawIsTaken(): void
    {
        $taken = $this->codes()->issue('q');
        $draws = [$taken, $taken, $taken];

        $this->expectException(\InvalidArgumentException::class);
        $this->drawing($draws)->issue('q');
    }

    public function testCodeIsUniqueWithinItsPurpose(): void
    {
        $draws = ['same-code-1234567890ab', 'same-code-1234567890ab'];
        $codes = $this->drawing($draws);
        $codes->issue('q');
        $codes->issue('t');

        $dm = $this->app()->container()->get(DataManager::class);
        $type = $dm->getTypeRegistry()->get('code');
        $driver = $dm->driver($type->storage);
        $this->assertInstanceOf(PdoDriver::class, $driver);
        $indexes = (new Schema($driver->pdo()))->indexes('code');
        $this->assertContains('uniq_code_code', $indexes);
        $this->assertContains('idx_code_recipient', $indexes);

        $this->expectException(ValidationException::class);
        $dm->saveType(DynamicRecord::fromArray($type, ['uuid' => 'x', 'purpose' => 'q', 'code' => 'same-code-1234567890ab', 'form' => 'random']), false);
    }
}
