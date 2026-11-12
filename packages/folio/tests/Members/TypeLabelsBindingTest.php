<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeLabels;
use Preflow\Folio\Content\DisplayTypeLabels;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;

/** Folio binds Data's TypeLabels to its display labels, in the asked language. */
final class TypeLabelsBindingTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        if (($this->dir ?? '') !== '') {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_type_labels_are_folios_display_labels_in_the_asked_locale(): void
    {
        $this->scaffold();
        $this->migrateAndApply();

        $labels = $this->container()->get(TypeLabels::class);

        $this->assertInstanceOf(DisplayTypeLabels::class, $labels);
        $this->assertSame('Pages', $labels->label('page', 'de'), 'a plain label of the model file');
        $this->assertSame('Members', $labels->label('member', 'en'));
        $this->assertSame('Mitglieder', $labels->label('member', 'de'));
        $this->assertSame('Unknown', $labels->label('unknown', 'de'), 'the key, capitalised, for a type without a label');
    }
}
