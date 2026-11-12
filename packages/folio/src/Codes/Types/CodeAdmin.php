<?php

declare(strict_types=1);

namespace Preflow\Folio\Codes\Types;

use Preflow\Codes\CodeRefused;
use Preflow\Codes\Codes;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\FormPanel;
use Preflow\Folio\Extension\ListColumn;
use Preflow\Folio\Extension\RecordAction;
use Preflow\Folio\Extension\SaveRecord;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\I18n\Translator;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Codes in the admin: a new code is a readable one (random codes are issued by code), made by
 * Codes::prepare() like an issued one (text normalised and unique within its purpose, the
 * purpose's ttl, created_at); once stored, only label, expiry and max uses change, by Codes::update()
 * (the #[SaveRecord] method writes; #[BeforeSave] only checks). Revoking and the redemptions are shown on the code. Its words are the "codes"
 * group's, in the admin's language (attribute labels given as keys).
 */
#[FolioType('code')]
final class CodeAdmin
{
    private const array KEPT = ['purpose', 'code', 'form', 'uses', 'revoked_at', 'payload'];

    public function __construct(
        private readonly Codes $codes,
        private readonly DataManager $dm,
        private readonly Translator $translator,
    ) {}

    /** Validates only (nothing is written here) and fills the record: a new code's columns from Codes::prepare(), a stored one's fixed fields. */
    #[BeforeSave]
    public function beforeSave(DynamicRecord $record): void
    {
        $maxUses = $this->maxUses($record->get('max_uses'));
        $expiresAt = $this->expiry($record->get('expires_at'));

        $stored = $this->dm->findType('code', (string) $record->getId());
        if ($stored !== null) {
            if ((string) ($stored->get('recipient') ?? '') !== '') {
                $maxUses = 1; // a bound code has exactly one use
            }
            try {
                $this->codes->checkUpdate((string) $record->getId(), (string) $record->get('label'), $expiresAt, $maxUses);
            } catch (CodeRefused $e) {
                throw $this->refused($e);
            }
            foreach (self::KEPT as $field) {
                $record->set($field, $stored->get($field));
            }
            $record->set('max_uses', $maxUses === null ? '' : (string) $maxUses);

            return;
        }

        try {
            $columns = $this->codes->prepare((string) $record->get('purpose'), (string) $record->get('code'), $expiresAt);
        } catch (CodeRefused $e) {
            throw $this->refused($e);
        }
        foreach ($columns as $field => $value) {
            $record->set($field, $value);
        }
        $record->set('max_uses', $maxUses === null ? '' : (string) $maxUses);
    }

    /** The write: a new code is inserted as prepared; a stored one goes through Codes::update(), so a use counted meanwhile is never overwritten. */
    #[SaveRecord]
    public function save(DynamicRecord $record, ?DynamicRecord $previous): void
    {
        if ($previous === null) {
            $this->dm->saveType($record, false);

            return;
        }
        try {
            $this->codes->update((string) $record->getId(), (string) $record->get('label'), $this->expiry($record->get('expires_at')), $this->maxUses($record->get('max_uses')));
        } catch (CodeRefused $e) {
            throw $this->refused($e);
        }
    }

    #[RecordAction(name: 'revoke', label: 'codes.actions.revoke', permission: 'codes.revoke', confirm: 'codes.actions.revoke_confirm')]
    public function revoke(DynamicRecord $record, ServerRequestInterface $request): ?ResponseInterface
    {
        $this->codes->revoke((string) $record->getId());

        return null;
    }

    #[ListColumn('codes.redemptions.title')]
    public function column(DynamicRecord $record): string
    {
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            return $this->translator->get('codes.redemptions.revoked');
        }
        if ($this->codes->expired($record->get('expires_at'))) {
            return $this->translator->get('codes.redemptions.expired');
        }

        return (string) (int) $record->get('uses');
    }

    #[FormPanel('codes.redemptions.title')]
    public function panel(DynamicRecord $record): ScreenView
    {
        $rows = [];
        foreach ($this->codes->redemptions((string) $record->getId()) as $redemption) {
            $rows[] = [
                'redeemer' => $redemption['redeemer'] ?? '',
                'at' => date(DATE_ATOM, $redemption['redeemed_at']), // shown with folio_date()
            ];
        }

        return new ScreenView('@codes/redemptions.twig', ['redemptions' => $rows]);
    }

    /**
     * A whole number, or null for unlimited: the number field may send '3.0', which MySQL's
     * strict mode refuses to cast to an integer in Codes' atomic use count.
     */
    private function maxUses(mixed $value): ?int
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (!is_numeric($text) || (float) $text < 0 || floor((float) $text) !== (float) $text) {
            throw $this->refuse('max_uses', 'max_uses');
        }

        return (int) $text;
    }

    private function expiry(mixed $value): ?\DateTimeImmutable
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($text);
        } catch (\Exception) {
            throw $this->refuse('expires_at', 'expiry');
        }
    }

    /** The field a refusal belongs to and the words for it. */
    private function refused(CodeRefused $e): ValidationException
    {
        $field = match ($e->reason) {
            CodeRefused::UNKNOWN_PURPOSE, CodeRefused::NOT_READABLE => 'purpose',
            CodeRefused::FORMAT, CodeRefused::TAKEN => 'code',
            CodeRefused::EXPIRY_PAST => 'expires_at',
            CodeRefused::MAX_USES_LOW, CodeRefused::BOUND_SINGLE_USE => 'max_uses',
            default => 'label', // revoked, used up, unknown: the whole code
        };

        return $this->refuse($field, $e->reason);
    }

    /** @param string $error a key of codes.errors */
    private function refuse(string $field, string $error): ValidationException
    {
        return new ValidationException(new ValidationResult([$field => [$this->translator->get('codes.errors.' . $error)]]));
    }
}
