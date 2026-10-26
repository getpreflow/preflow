<?php

declare(strict_types=1);

namespace Preflow\Codes\Types;

use Preflow\Codes\Codes;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\FormPanel;
use Preflow\Folio\Extension\ListColumn;
use Preflow\Folio\Extension\RecordAction;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\I18n\Translator;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Codes in the admin: a new code is a readable one (random codes are issued by code), its
 * text normalised and unique within its purpose; once stored, only label, expiry and max
 * uses change. Revoking and the redemptions are shown on the code. Its words are the "codes"
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

    #[BeforeSave]
    public function beforeSave(DynamicRecord $record): void
    {
        $record->set('max_uses', $this->maxUses($record->get('max_uses')));

        $stored = $this->dm->findType('code', (string) $record->getId());
        if ($stored !== null) {
            foreach (self::KEPT as $field) {
                $record->set($field, $stored->get($field));
            }

            return;
        }

        $purpose = (string) $record->get('purpose');
        if (!isset($this->codes->purposes()[$purpose])) {
            throw $this->refuse('purpose', 'purpose');
        }
        if (!$this->codes->purpose($purpose)->readable) {
            throw $this->refuse('purpose', 'not_readable');
        }
        $code = Codes::normalizeReadable((string) $record->get('code'));
        if ($code === null || preg_match('/^[A-Z0-9]{4,64}$/', $code) !== 1) {
            throw $this->refuse('code', 'format');
        }
        foreach ($this->dm->queryType('code')->where('purpose', '=', $purpose)->get()->items() as $other) {
            if ((string) $other->get('code') === $code) {
                throw $this->refuse('code', 'taken');
            }
        }

        $record->set('code', $code);
        $record->set('form', 'readable');
        $record->set('uses', 0);
        $record->set('revoked_at', '');
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
     * A whole-number string, or '' for unlimited: the number field may send '3.0', which
     * MySQL's strict mode refuses to cast to an integer in Codes' atomic use count.
     */
    private function maxUses(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }
        if (!is_numeric($text) || (float) $text < 0 || floor((float) $text) !== (float) $text) {
            throw $this->refuse('max_uses', 'max_uses');
        }

        return (string) (int) $text;
    }

    /** @param string $error a key of codes.errors */
    private function refuse(string $field, string $error): ValidationException
    {
        return new ValidationException(new ValidationResult([$field => [$this->translator->get('codes.errors.' . $error)]]));
    }
}
