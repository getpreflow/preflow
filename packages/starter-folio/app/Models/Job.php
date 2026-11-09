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
    public ?string $slug = null;

    /** The uuid of the company that posted the job; Folio shows it as a pick from the companies. */
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

    #[Field]
    public ?string $createdAt = null;

    #[Field]
    public ?string $updatedAt = null;

    /**
     * When Folio published this copy (ISO 8601). Only a published copy read through PublishedModels
     * has it; it comes from the snapshot's `_published_at` column. Not a field: no column of its own,
     * nothing Folio's form shows, and a save never writes it.
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
