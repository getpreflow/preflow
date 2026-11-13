<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Relation;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;
use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A job ad. `type: 'job'` makes it a Folio type too: config/models/job.json says how Folio lists
 * it, and Folio's publishing decides what the public sees (there is no status field).
 */
#[Entity(table: 'jobs', storage: 'default', type: 'job')]
final class Job extends Model
{
    /** Salaries are whole numbers of this currency per year. */
    public const string CURRENCY = 'EUR';

    #[Id]
    public ?string $uuid = null;

    #[Field(searchable: true)]
    #[Validate('required', 'max:120')]
    public ?string $title = null;

    #[Field(unique: true, length: 140)]
    #[Validate('required', 'slug')]
    public ?string $slug = null;

    /** The uuid of the company that posted the job; Folio shows it as a pick from the companies. */
    #[Field(index: true)]
    #[Relation(to: 'company', onDelete: 'restrict', labelField: 'name')]
    #[Validate('required')]
    public ?string $company = null;

    #[Field]
    #[Validate('required', 'max:80')]
    public ?string $place = null;

    #[Field(type: 'select', config: ['options' => [
        'full_time' => 'Full time',
        'part_time' => 'Part time',
        'contract' => 'Contract',
        'internship' => 'Internship',
    ]])]
    #[Validate('required', 'in:full_time,part_time,contract,internship')]
    public ?string $employmentType = null;

    #[Field(type: 'select', config: ['options' => [
        'no' => 'On site',
        'hybrid' => 'Hybrid',
        'full' => 'Remote',
    ]])]
    #[Validate('required', 'in:no,hybrid,full')]
    public ?string $remote = null;

    #[Field]
    #[Validate('nullable', 'integer', 'min:0')]
    public ?int $salaryFrom = null;

    #[Field]
    #[Validate('nullable', 'integer', 'min:0')]
    public ?int $salaryTo = null;

    /** Markdown, rendered by the app (never stored as HTML). Folio edits it as plain monospace text. */
    #[Field(type: 'text', searchable: true, config: ['monospace' => true])]
    #[Validate('required', 'min:30')]
    public ?string $description = null;

    /**
     * The date the list sorts by and the job shows as "Posted". Set on the first publish from the
     * employer area (JobWriter), and kept after that: publishing an edit, or unpublishing and
     * publishing again, doesn't move a job to the top. Staff set it in Folio's form.
     */
    #[Field(type: 'date', label: 'Posted on')]
    public ?string $postedOn = null;

    // hidden: set by JobWriter, not typed into Folio's form.
    #[Field(config: ['hidden' => true])]
    public ?string $createdAt = null;

    #[Field(config: ['hidden' => true])]
    public ?string $updatedAt = null;

    /**
     * When Folio last published this copy (ISO 8601): the order among jobs posted on the same day,
     * and "Posted" for a job staff published without a date. Not a field (no column, not in
     * Folio's form, never saved): DataManager fills public properties from the snapshot row, and
     * only a published copy (PublishedModels) has a `_published_at` column.
     */
    public ?string $_published_at = null;

    /**
     * Rules that need two fields. They replace the attribute rules of the same field, so
     * salaryTo repeats its own.
     *
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'salaryTo' => [
                new Nullable(),
                'integer',
                'min:0',
                new class implements ValidationRule {
                    public function validate(mixed $value, ValidationContext $context): true|string
                    {
                        $from = $context->getValue('salaryFrom');
                        if ($from !== null && $value !== null && (int) $value < (int) $from) {
                            return 'The salary range must not end below its start.';
                        }

                        return true;
                    }
                },
            ],
        ];
    }
}
