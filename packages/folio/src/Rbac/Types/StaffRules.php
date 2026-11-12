<?php

declare(strict_types=1);

namespace Preflow\Folio\Rbac\Types;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffRefused;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * The staff rules (StaffAccounts::checkSave() and checkDelete(): e-mail, delegation, lock-out)
 * in Folio's terms: a refused save is a ValidationException on the field it is about, a refused
 * delete Folio's Refused. The actor is whoever is signed in for the request (null on the CLI).
 * Folio's write paths run it: the form's save and delete (StaffAdmin's hooks); its record actions
 * call StaffAccounts, which runs the same rules. A crafted POST gets the same answers as the form;
 * the roles field only disables what is refused here.
 */
final class StaffRules
{
    public function __construct(
        private readonly StaffAccounts $accounts,
        private readonly PermissionCoverage $coverage,
    ) {}

    public function beforeSave(DynamicRecord $record): void
    {
        try {
            $this->accounts->checkSave($record, $this->coverage->actor());
        } catch (StaffRefused $e) {
            throw new ValidationException(new ValidationResult([$e->field ?? 'roles' => [$e->getMessage()]]));
        }
    }

    public function beforeDelete(DynamicRecord $record): void
    {
        try {
            $this->accounts->checkDelete($record, $this->coverage->actor());
        } catch (StaffRefused $e) {
            throw new Refused($e->getMessage());
        }
    }
}
