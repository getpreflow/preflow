<?php

declare(strict_types=1);

namespace App\Folio\Demo\Types;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\FormPanel;
use Preflow\Folio\Extension\RecordAction;

#[FolioType('note')]
final class NoteTweaks
{
    public function __construct(private readonly DataManager $data) {}

    #[FormPanel('Name length')]
    public function nameLength(DynamicRecord $record): string
    {
        return mb_strlen((string) $record->get('name')) . ' characters';
    }

    #[RecordAction('shout', 'Shout', 'demo.shout', icon: 'ui-check', confirm: 'Upper-case this note\'s name?')]
    public function shout(DynamicRecord $record): null
    {
        $record->set('name', mb_strtoupper((string) $record->get('name')));
        $this->data->saveType($record, false);

        return null;
    }
}
