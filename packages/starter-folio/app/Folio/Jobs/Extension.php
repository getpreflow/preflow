<?php

declare(strict_types=1);

namespace App\Folio\Jobs;

use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Folio\Extension\Extension as FolioExtensionBase;

/**
 * The job board's additions to the Folio admin (enabled in config/folio/extensions.json):
 * Types/CompanyAdmin adds an "Open jobs" column to the company list and a notice on the
 * dashboard while jobs wait unpublished.
 */
#[FolioExtension(name: 'jobs', label: 'Jobs', description: 'Job board additions to the Folio admin')]
final class Extension extends FolioExtensionBase
{
}
