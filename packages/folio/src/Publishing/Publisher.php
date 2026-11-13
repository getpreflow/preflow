<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Core\PageCache\PageCache;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\ReviewIndexUpdater;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Publishing\LanguageChecks;
use Preflow\Publishing\PublishingTypes;
use Preflow\Publishing\RecordAddresses as PublishingAddresses;
use Preflow\Publishing\RecordChecks;
use Preflow\Publishing\RecordIndex;

/**
 * @deprecated since 0.71, use Preflow\Publishing\Publisher (preflow/publishing).
 *
 * The Publisher under its name of before 0.71, for one release. It takes the old arguments, the
 * short (DataManager, TypeCatalog, RecordValidator) and the full list, and wraps them in Folio's
 * adapters; it also takes the new ones. Folio's container hands out this class for 0.71, so a
 * type-hint of either name accepts the Publisher it gets. In 0.72 this class goes and
 * Preflow\Publishing\Publisher is final again.
 */
class Publisher extends \Preflow\Publishing\Publisher
{
    public function __construct(
        DataManager $dm,
        TypeCatalog|PublishingTypes $types,
        RecordValidator|RecordChecks $checks,
        ?int $keepRevisions = 20,
        ?\Closure $now = null,
        TranslationStatus|LanguageChecks|null $languages = null,
        bool $completeLanguages = false,
        ReviewIndexUpdater|RecordIndex|null $index = null,
        RecordAddresses|PublishingAddresses|null $addresses = null,
        ?PageCache $pageCache = null,
        ?FrontendResolver $resolver = null,
    ) {
        parent::__construct(
            $dm,
            $types instanceof TypeCatalog ? new FolioPublishingTypes($types) : $types,
            $checks instanceof RecordValidator ? new FolioRecordChecks($checks) : $checks,
            $keepRevisions,
            $now,
            $languages instanceof TranslationStatus ? new FolioLanguageChecks($languages) : $languages,
            $completeLanguages,
            $index,
            $addresses instanceof PublishingAddresses ? $addresses : (
                $addresses !== null || ($pageCache !== null && $resolver !== null) ? new FolioRecordAddresses($addresses, $pageCache, $resolver) : null
            ),
        );
    }
}
