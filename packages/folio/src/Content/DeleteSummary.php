<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DeletePlan;
use Preflow\Folio\FolioText;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Publishing\DeleteRefused;

/**
 * What a delete (Preflow\Publishing\RecordDeleter) does, in the editor's words: the confirmation
 * for a plan, and the reason a delete was refused.
 */
final class DeleteSummary
{
    public function __construct(
        private readonly DisplayLabels $labels,
        private readonly ?RecordAddresses $addresses = null,
    ) {}

    /**
     * The confirmation for a plan: "This can't be undone." and a line per type of
     * what else happens; a restricted plan gives the refusal instead. "addresses" counts
     * the public addresses the plan's deleted records hold (those deleted by cascade too),
     * so the admin can ask where their old links go ("Send its old links to…"). A restricted plan also
     * lists what still points here, per type ("used_by": "2 jobs", "1 page"), for the dialog's list.
     *
     * @return array{restricted: bool, lines: list<string>, confirm: string, addresses: int, used_by?: list<string>}
     */
    public function summary(DeletePlan $plan): array
    {
        if ($plan->restricted()) {
            $text = $this->restrictedText($plan);

            return ['restricted' => true, 'lines' => [$text], 'confirm' => $text, 'addresses' => 0, 'used_by' => $this->usedBy($plan)];
        }

        $counts = $plan->counts();
        $lines = [];
        foreach ($counts['deleted'] as $type => $n) {
            $lines[] = FolioText::get('folio.delete.also_deletes', ['count' => $n, 'noun' => $this->noun($type, $n)]);
        }
        foreach ($counts['detached'] as $type => $n) {
            $lines[] = FolioText::choice('folio.delete.loses_link', $n, ['noun' => $this->noun($type, $n)]);
        }

        return [
            'restricted' => false,
            'lines' => $lines,
            'confirm' => implode("\n", [FolioText::get('folio.delete.cannot_undo'), ...$lines]),
            'addresses' => $this->addressCount($plan),
        ];
    }

    /** Why the delete was refused: records still point here, or a record losing its link refused the save. */
    public function refusal(DeleteRefused $refused): string
    {
        if ($refused->record === null) {
            return $this->restrictedText($refused->plan);
        }

        return FolioText::get('folio.delete.refused', [
            'noun' => $this->noun($refused->record->getType()->key, 1),
            'messages' => implode(' ', self::messages($refused->validation?->errors() ?? [])),
        ]);
    }

    /** The public addresses (one per record and language) of the records the plan deletes. */
    private function addressCount(DeletePlan $plan): int
    {
        if ($this->addresses === null) {
            return 0;
        }
        $count = 0;
        foreach ($plan->deletes() as $type => $ids) {
            foreach ($ids as $id) {
                $count += count($this->addresses->of($type, (string) $id));
            }
        }

        return $count;
    }

    private function restrictedText(DeletePlan $plan): string
    {
        $total = array_sum($plan->restrictions());

        return FolioText::choice('folio.delete.restricted', $total, ['records' => self::join($this->usedBy($plan))]);
    }

    /** @return list<string> "2 jobs", "1 page": the records that still point here, per type */
    private function usedBy(DeletePlan $plan): array
    {
        $parts = [];
        foreach ($plan->restrictions() as $type => $n) {
            $parts[] = sprintf('%d %s', $n, $this->noun($type, $n));
        }

        return $parts;
    }

    /** The type's label as a noun for $n records ("jobs", "job"): DisplayLabels::noun(). */
    private function noun(string $type, int $n): string
    {
        return $this->labels->noun($type, $n);
    }

    /** @param list<string> $parts "a", "a and b", "a, b and c" */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : FolioText::get('folio.delete.and', ['list' => implode(', ', $parts), 'last' => (string) $last]);
    }

    /**
     * @param array<mixed> $errors
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        $messages = [];
        array_walk_recursive($errors, static function (mixed $message) use (&$messages): void {
            if (is_string($message)) {
                $messages[] = $message;
            }
        });

        return $messages;
    }
}
