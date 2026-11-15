<?php

declare(strict_types=1);

namespace Preflow\Ai\Limits;

use Preflow\Core\Throttle\Throttle;

/**
 * Per-person limits for AI work started in an admin UI (config/ai.php `limits`): `draft` counts
 * drafts, `translate` translated items (a record of a job, a set of labels, an alt text): what
 * costs money, not the requests around it. A limit is `max` per `per` seconds for each signed-in
 * person; `max` 0 turns it off. Counted through core's Throttle (purpose "ai.{action}") under the
 * person's hashed id (Throttle::forPerson()), so the raw auth id is never stored.
 *
 * Any admin UI calls it before AI work it starts on a person's behalf: take() counts one, check()
 * only asks whether budget is left (before queueing work that take() counts later). Work started
 * from the command line has no person and is not limited. Bound only when a Throttle is (an app
 * with config/data.php); without it nothing is limited.
 *
 * @since 0.78
 */
final class AiLimits
{
    public const string DRAFT = 'draft';

    public const string TRANSLATE = 'translate';

    /** @var array<string, array{max: int, per: int}> */
    public const array DEFAULTS = [
        self::DRAFT => ['max' => 30, 'per' => 3600],
        self::TRANSLATE => ['max' => 100, 'per' => 3600],
    ];

    /** @var array<string, array{max: int, per: int}> */
    private readonly array $limits;

    /**
     * @param array<string, mixed> $limits config/ai.php `limits`, merged over DEFAULTS per action
     * @throws \InvalidArgumentException naming the setting (ai.limits.{action}.max / .per) that is not a whole number in range
     */
    public function __construct(
        private readonly Throttle $throttle,
        array $limits = self::DEFAULTS,
    ) {
        $merged = [];
        foreach (self::DEFAULTS as $action => $default) {
            $given = $limits[$action] ?? [];
            if (!is_array($given)) {
                throw new \InvalidArgumentException("config/ai.php ai.limits.{$action} must be an array with max and per.");
            }
            $limit = $given + $default;
            if (!is_int($limit['max']) || $limit['max'] < 0) {
                throw new \InvalidArgumentException("config/ai.php ai.limits.{$action}.max must be a whole number of 0 or more (0 turns the limit off).");
            }
            if (!is_int($limit['per']) || $limit['per'] < 1) {
                throw new \InvalidArgumentException("config/ai.php ai.limits.{$action}.per must be a whole number of seconds, 1 or more.");
            }
            $merged[$action] = ['max' => $limit['max'], 'per' => $limit['per']];
        }
        $this->limits = $merged;
    }

    /**
     * Counts one $action for the person.
     *
     * @throws AiLimitReached when this one passes the limit
     * @throws \InvalidArgumentException for an unknown action
     */
    public function take(string $action, string $authId): void
    {
        $limit = $this->limit($action);
        if ($limit === null) {
            return;
        }
        $client = $this->throttle->forPerson($authId);
        $hits = $this->throttle->hit(self::purpose($action), $client, $limit['per']);
        if ($hits > $limit['max']) {
            throw $this->reached($action, $limit, $client);
        }
    }

    /**
     * Whether the person has budget left for $action; counts nothing.
     *
     * @throws AiLimitReached when none is left
     * @throws \InvalidArgumentException for an unknown action
     */
    public function check(string $action, string $authId): void
    {
        $limit = $this->limit($action);
        if ($limit === null) {
            return;
        }
        $client = $this->throttle->forPerson($authId);
        if ($this->throttle->tooMany(self::purpose($action), $client, $limit['max'], $limit['per'])) {
            throw $this->reached($action, $limit, $client);
        }
    }

    /**
     * The action's limit, or null when it is off (max 0).
     *
     * @return array{max: int, per: int}|null
     * @throws \InvalidArgumentException for an unknown action
     */
    public function limit(string $action): ?array
    {
        $limit = $this->limits[$action] ?? throw new \InvalidArgumentException("Unknown AI action \"{$action}\"; known: " . implode(', ', array_keys(self::DEFAULTS)) . '.');

        return $limit['max'] === 0 ? null : $limit;
    }

    /** @param array{max: int, per: int} $limit */
    private function reached(string $action, array $limit, string $client): AiLimitReached
    {
        return new AiLimitReached($action, $limit['max'], max(1, $this->throttle->retryAfter(self::purpose($action), $client, $limit['per'])));
    }

    private static function purpose(string $action): string
    {
        return 'ai.' . $action;
    }
}
