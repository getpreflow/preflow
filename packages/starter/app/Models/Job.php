<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;
use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

#[Entity(table: 'jobs', storage: 'default')]
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

    /** The uuid of the company that posted the job. */
    #[Field(index: true)]
    #[Validate('required')]
    public ?string $company = null;

    #[Field]
    #[Validate('required', 'max:80')]
    public ?string $place = null;

    #[Field]
    #[Validate('required', 'in:full_time,part_time,contract,internship')]
    public ?string $employmentType = null;

    #[Field]
    #[Validate('required', 'in:no,hybrid,full')]
    public ?string $remote = null;

    #[Field]
    #[Validate('nullable', 'integer', 'min:0')]
    public ?int $salaryFrom = null;

    #[Field]
    #[Validate('nullable', 'integer', 'min:0')]
    public ?int $salaryTo = null;

    /** Markdown, rendered by the app (never stored as HTML). */
    #[Field(searchable: true)]
    #[Validate('required', 'min:30')]
    public ?string $description = null;

    #[Field]
    #[Validate('in:draft,published')]
    public ?string $status = 'draft';

    #[Field]
    public ?string $publishedAt = null;

    #[Field]
    public ?string $createdAt = null;

    #[Field]
    public ?string $updatedAt = null;

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
