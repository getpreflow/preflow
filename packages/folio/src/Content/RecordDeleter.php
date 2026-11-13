<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\AfterDelete;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Folio\Publishing\FolioRecordAddresses;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Publishing\DeleteRefused;
use Preflow\Publishing\NoSaveHooks;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishingTypes;
use Preflow\Publishing\RecordAddresses as PublishingAddresses;
use Preflow\Publishing\RecordIndex;
use Preflow\Publishing\RemovalTarget;
use Preflow\Publishing\SaveHooks;
use Preflow\Publishing\WriteKind;

/**
 * @deprecated since 0.71, use Preflow\Publishing\RecordDeleter (preflow/publishing) and
 *             DeleteSummary for the texts.
 *
 * The deleter under its name of before 0.71, for one release. It takes the old arguments: the
 * TypeCatalog (now only for the labels; which types publish, the Publisher decides), the hooks as
 * a Customizations::runHooks closure or SaveHooks, DisplayLabels, the review index and Folio's
 * address book. It keeps summary() and refuses with Folio's Refused and its text as before.
 * In 0.72 this class goes and Preflow\Publishing\RecordDeleter is final again.
 */
class RecordDeleter extends \Preflow\Publishing\RecordDeleter
{
    private readonly DeleteSummary $summary;

    /**
     * @param (\Closure(class-string, DynamicRecord): void)|SaveHooks|null $hooks runs a type's hooks
     *        (Customizations::runHooks); null: no hooks
     */
    public function __construct(
        DataManager $dm,
        TypeCatalog|PublishingTypes $catalog,
        ?Publisher $publisher = null,
        \Closure|SaveHooks|null $hooks = null,
        ?DisplayLabels $labels = null,
        ?RecordIndex $review = null,
        RecordAddresses|PublishingAddresses|null $addresses = null,
    ) {
        if ($labels === null && !$catalog instanceof TypeCatalog) {
            throw new \InvalidArgumentException('RecordDeleter needs DisplayLabels without a TypeCatalog.');
        }
        parent::__construct(
            $dm,
            $publisher,
            $hooks instanceof \Closure ? self::closureHooks($hooks) : ($hooks ?? new NoSaveHooks()),
            $review,
            $addresses instanceof RecordAddresses ? new FolioRecordAddresses($addresses, null, null) : $addresses,
        );
        $this->summary = new DeleteSummary($labels ?? new DisplayLabels($catalog), $addresses instanceof RecordAddresses ? $addresses : null);
    }

    /** @throws Refused restricted, or a hook refused (nothing was written) */
    public function delete(string $type, array $ids, ?RemovalTarget $target = null): void
    {
        try {
            parent::delete($type, $ids, $target);
        } catch (DeleteRefused $e) {
            throw new Refused($this->summary->refusal($e), 0, $e);
        }
    }

    /** @return array{restricted: bool, lines: list<string>, confirm: string, addresses: int} */
    public function summary(DeletePlan $plan): array
    {
        return $this->summary->summary($plan);
    }

    /** @param \Closure(class-string, DynamicRecord): void $run */
    private static function closureHooks(\Closure $run): SaveHooks
    {
        return new class ($run) implements SaveHooks {
            public function __construct(private readonly \Closure $run) {}

            public function before(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
            {
                ($this->run)(BeforeSave::class, $record);
            }

            public function after(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
            {
                ($this->run)(AfterSave::class, $record);
            }

            public function beforeDelete(DynamicRecord $record): void
            {
                ($this->run)(BeforeDelete::class, $record);
            }

            public function afterDelete(DynamicRecord $record): void
            {
                ($this->run)(AfterDelete::class, $record);
            }

            public function replacesSave(string $type): bool
            {
                return false;
            }

            public function save(DynamicRecord $record, ?DynamicRecord $previous): void
            {
                throw new \LogicException('The hooks closure saves no records.');
            }
        };
    }
}
