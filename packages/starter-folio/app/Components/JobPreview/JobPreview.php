<?php

declare(strict_types=1);

namespace App\Components\JobPreview;

use App\Jobs\JobResource;
use App\Jobs\JobSearch;
use App\Models\Job;
use Preflow\Components\Component;

/**
 * The job form's live preview in Folio (config/models/job.json, "preview"). Folio gives the view's
 * component the record as typed (`record`) and the view's key (`view`: card or row). This turns the
 * record into the array JobCard takes, through JobResource, so the preview and the site show a
 * job the same way.
 */
final class JobPreview extends Component
{
    /** @var array<string, mixed> */
    public array $job = [];
    public string $cardView = 'cards';

    public function __construct(
        private readonly JobSearch $search,
        private readonly JobResource $resource,
    ) {}

    public function resolveState(): void
    {
        $values = (array) ($this->props['record'] ?? []);
        // A form sends text: an empty salary is none, a typed one a whole number (Job's properties are ?int).
        foreach (['salaryFrom', 'salaryTo'] as $field) {
            $value = $values[$field] ?? null;
            $values[$field] = is_numeric($value) ? (int) $value : null;
        }
        $job = new Job();
        $job->fill($values);
        // The company is read the way the preview reads every linked record: its working copy.
        $company = $job->company === null || $job->company === '' ? null : $this->search->company($job->company);

        $this->job = $this->resource->toArray($job, $company);
        $this->cardView = ($this->props['view'] ?? 'card') === 'row' ? 'list' : 'cards';
    }
}
