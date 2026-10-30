<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use Preflow\Data\Query;
use Preflow\Folio\Transfer\Importer;

class ImportReplacesSqliteTest extends ImportReplacesTestCase
{
    protected function storage(): string
    {
        return 'sqlite';
    }

    /** A column the table does not have: the database refuses the row. */
    protected function breakingFields(): array
    {
        return ['no_such_column' => 1];
    }

    public function test_a_second_table_failing_rolls_back_the_first(): void
    {
        $local = ['a' => ['title' => 'Local', 'slug' => 'x']];
        $this->setGames($local);
        [$importer] = $this->importerWithData();
        $plan = $importer->read($this->archive([
            'game' => ['b' => ['title' => 'Live', 'slug' => 'x']],
            'post' => ['p9' => ['no_such_column' => 1]],
        ]), 'data');
        $this->assertSame(['game', 'post'], array_keys($plan->tables), 'game is written before post fails');

        $e = $this->applyFailing($importer, $plan);

        $this->assertSame(Importer::RECORDS_UNCHANGED, $e->getCode());
        $this->assertSame($local, $this->games());
        $this->assertSame([], $this->gameDriver()->findMany('post', new Query())->items());
    }

    public function test_in_a_transaction_the_caller_opened_it_does_not_claim_nothing_changed(): void
    {
        $this->setGames(['a' => ['title' => 'Local', 'slug' => 'x']]);
        [$importer, $dm] = $this->importerWithData();
        $plan = $importer->read($this->gameArchive(['b' => ['title' => 'Live', 'slug' => 'y'], 'z' => $this->breakingFields()]), 'data');
        $pdo = $dm->driver($this->storage())->pdo();
        $pdo->beginTransaction();
        try {
            $e = $this->applyFailing($importer, $plan);
        } finally {
            $pdo->rollBack();
        }

        $this->assertSame(0, $e->getCode());
        $this->assertSame(['a' => ['title' => 'Local', 'slug' => 'x']], $this->games());
    }
}
