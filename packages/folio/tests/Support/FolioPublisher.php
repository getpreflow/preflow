<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Core\PageCache\PageCache;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\ReviewIndexUpdater;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\FolioLanguageChecks;
use Preflow\Folio\Publishing\FolioPublishingTypes;
use Preflow\Folio\Publishing\FolioRecordAddresses;
use Preflow\Folio\Publishing\FolioRecordChecks;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordColumns;

/**
 * A Publisher for a test built by hand, wired with Folio's adapters as FolioServiceProvider wires the
 * container's: the types of the catalog, the rules of the RecordValidator, the languages of the
 * TranslationStatus, the review index and the addresses (the redirect book, the page cache).
 */
final class FolioPublisher
{
    public static function make(
        DataManager $dm,
        TypeCatalog $types,
        RecordValidator $checks,
        ?int $keepRevisions = 20,
        ?\Closure $now = null,
        ?TranslationStatus $languages = null,
        bool $completeLanguages = false,
        ?ReviewIndexUpdater $index = null,
        ?RecordAddresses $addresses = null,
        ?PageCache $pageCache = null,
        ?FrontendResolver $resolver = null,
        ?RecordColumns $columns = null,
    ): Publisher {
        return new Publisher(
            $dm,
            new FolioPublishingTypes($types),
            new FolioRecordChecks($checks),
            $keepRevisions,
            $now,
            $languages !== null ? new FolioLanguageChecks($languages) : null,
            $completeLanguages,
            $index,
            $addresses !== null || ($pageCache !== null && $resolver !== null) ? new FolioRecordAddresses($addresses, $pageCache, $resolver) : null,
            $columns,
        );
    }
}
