<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Validation\ValidationException;

/**
 * Publishes working copies whose "Publish at" (_publish_at) is due. Publishing
 * clears the schedule, so a second run publishes nothing; a record that fails
 * validation keeps its schedule and is reported on every run until fixed.
 */
final class Scheduler
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly PublishingTypes $types,
        private readonly DataManager $dm,
    ) {}

    /** @return array{published: list<string>, failed: array<string, string>} keyed "type/id" */
    public function runDue(\DateTimeImmutable $now): array
    {
        $published = [];
        $failed = [];
        foreach ($this->types->publishing() as $type) {
            foreach ($this->dm->queryType($type)->get()->items() as $working) {
                $at = $working->get('_publish_at');
                $due = is_string($at) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $at, $now->getTimezone()) : false;
                if ($due === false || $due > $now) {
                    continue;
                }
                $key = $type . '/' . $working->getId();
                try {
                    $this->publisher->publish($working, 'scheduler');
                    $published[] = $key;
                } catch (ValidationException $e) {
                    $failed[$key] = self::firstMessage($e);
                }
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }

    public static function firstMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $field => $messages) {
            return $field . ': ' . ((array) $messages)[0];
        }

        return 'invalid';
    }
}
