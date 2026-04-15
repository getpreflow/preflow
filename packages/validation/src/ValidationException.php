<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        private readonly ValidationResult $result,
    ) {
        parent::__construct('Validation failed.');
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->result->errors();
    }

    public function errorBag(): ErrorBag
    {
        return new ErrorBag($this->result);
    }
}
