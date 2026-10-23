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
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Codes in the admin: a new code is a readable one (random codes are issued by code), its
 * text normalised and unique within its purpose; once stored, only label, expiry and max
 * uses change. Revoking and the redemptions are shown on the code.
 */
#[FolioType('code')]
final class CodeAdmin
{
    private const array KEPT = ['purpose', 'code', 'form', 'uses', 'revoked_at', 'payload'];

    public function __construct(
        private readonly Codes $codes,
        private readonly DataManager $dm,
    ) {}

    #[BeforeSave]
    public function beforeSave(DynamicRecord $record): void
    {
        $record->set('max_uses', self::maxUses($record->get('max_uses')));

        $stored = $this->dm->findType('code', (string) $record->getId());
        if ($stored !== null) {
            foreach (self::KEPT as $field) {
                $record->set($field, $stored->get($field));
            }

            return;
        }

        $purpose = (string) $record->get('purpose');
        if (!isset($this->codes->purposes()[$purpose])) {
            throw self::refuse('purpose', 'Choose a purpose.');
        }
        if (!$this->codes->purpose($purpose)->readable) {
            throw self::refuse('purpose', 'This purpose does not allow readable codes; random codes are issued by code.');
        }
        $code = Codes::normalizeReadable((string) $record->get('code'));
        if ($code === null || preg_match('/^[A-Z0-9]{4,64}$/', $code) !== 1) {
            throw self::refuse('code', 'A readable code needs 4 to 64 letters A-Z and digits (spaces and dashes are ignored).');
        }
        foreach ($this->dm->queryType('code')->where('purpose', '=', $purpose)->get()->items() as $other) {
            if ((string) $other->get('code') === $code) {
                throw self::refuse('code', 'A code like this already exists for this purpose.');
            }
        }

        $record->set('code', $code);
        $record->set('form', 'readable');
        $record->set('uses', 0);
        $record->set('revoked_at', '');
    }

    #[RecordAction(name: 'revoke', label: 'Revoke', permission: 'codes.revoke', confirm: 'Revoke this code? It stops working at once.')]
    public function revoke(DynamicRecord $record, ServerRequestInterface $request): ?ResponseInterface
    {
        $this->codes->revoke((string) $record->getId());

        return null;
    }

    #[ListColumn('Redemptions')]
    public function column(DynamicRecord $record): string
    {
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            return 'revoked';
        }
        if ($this->codes->expired($record->get('expires_at'))) {
            return 'expired';
        }

        return (string) (int) $record->get('uses');
    }

    #[FormPanel('Redemptions')]
    public function panel(DynamicRecord $record): ScreenView
    {
        $rows = [];
        foreach ($this->codes->redemptions((string) $record->getId()) as $redemption) {
            $rows[] = [
                'redeemer' => $redemption['redeemer'] ?? '',
                'at' => date('Y-m-d H:i', $redemption['redeemed_at']),
            ];
        }

        return new ScreenView('@codes/redemptions.twig', ['redemptions' => $rows]);
    }

    /**
     * A whole-number string, or '' for unlimited: the number field may send '3.0', which
     * MySQL's strict mode refuses to cast to an integer in Codes' atomic use count.
     */
    private static function maxUses(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }
        if (!is_numeric($text) || (float) $text < 0 || floor((float) $text) !== (float) $text) {
            throw self::refuse('max_uses', 'Max uses must be a whole number (empty: unlimited).');
        }

        return (string) (int) $text;
    }

    private static function refuse(string $field, string $message): ValidationException
    {
        return new ValidationException(new ValidationResult([$field => [$message]]));
    }
}
