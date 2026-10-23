<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Members\Admission\OwnerApproval;

/**
 * config/members.php: every key optional, the defaults are smart-stack.works' values. The
 * constants are names stored data depends on (type, guard, permissions, token purposes);
 * they are listed here so a site can see them, not to be renamed.
 */
final class MembersConfig
{
    public const string TYPE = 'member';
    public const string GUARD = 'members';
    public const string PERMISSION = 'members.access';
    public const string DECIDE = 'members.decide';
    public const string CONFIRM_PURPOSE = 'members.confirm';
    public const string RESET_PURPOSE = 'members.reset';
    /** The storage alias of the member type; without it the type lives on "default". */
    public const string STORAGE = 'members';

    private const array DEFAULTS = [
        'path' => '/members',
        'landing' => null,
        'layout' => '@folio/frontend/_site.twig',
        'mail' => ['from_name' => null, 'layout' => '@members/mail/layout.twig', 'accent' => '#d42a34'],
        'admission' => OwnerApproval::class,
        // [attempts, seconds] per throttle
        'throttle' => ['login' => [10, 900], 'register' => [5, 3600], 'forgot' => [5, 3600], 'resend' => [3, 3600]],
        'password_min' => 10,
        'confirm_ttl' => 86400,
        'reset_ttl' => 3600,
        'retention' => ['unconfirmed_days' => 7, 'declined_days' => 30],
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    /** @param array<string, mixed> $config the array config/members.php returns; nested groups merge key by key */
    public function __construct(array $config = [])
    {
        $merged = $config + self::DEFAULTS;
        foreach (['mail', 'throttle', 'retention'] as $group) {
            $merged[$group] = (is_array($config[$group] ?? null) ? $config[$group] : []) + self::DEFAULTS[$group];
        }
        $this->config = $merged;
    }

    /** A missing file means all defaults. */
    public static function fromFile(string $file): self
    {
        $config = is_file($file) ? require $file : [];

        return new self(is_array($config) ? $config : []);
    }

    /** Base path of the member pages, one leading slash and none trailing ("/members"). */
    public function path(): string
    {
        return '/' . trim((string) $this->config['path'], '/');
    }

    /** Where sign-in lands; the members home ({@see path()}) unless set. */
    public function landing(): string
    {
        $landing = $this->config['landing'];

        return is_string($landing) && $landing !== '' ? $landing : $this->path();
    }

    /** The page layout the member templates extend. */
    public function layout(): string
    {
        return (string) $this->config['layout'];
    }

    public function mailFromName(): ?string
    {
        $name = $this->config['mail']['from_name'];

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function mailLayout(): string
    {
        return (string) $this->config['mail']['layout'];
    }

    /** The link colour of the HTML mails. */
    public function accent(): string
    {
        return (string) $this->config['mail']['accent'];
    }

    /** @return class-string the configured {@see Admission\Admission} */
    public function admission(): string
    {
        return (string) $this->config['admission'];
    }

    /** @return array{0: int, 1: int} attempts, seconds ([0, 0] for an unknown throttle) */
    public function throttle(string $name): array
    {
        $pair = (array) ($this->config['throttle'][$name] ?? []);

        return [(int) ($pair[0] ?? 0), (int) ($pair[1] ?? 0)];
    }

    public function passwordMin(): int
    {
        return (int) $this->config['password_min'];
    }

    public function confirmTtl(): int
    {
        return (int) $this->config['confirm_ttl'];
    }

    public function resetTtl(): int
    {
        return (int) $this->config['reset_ttl'];
    }

    /** @param string $which unconfirmed or declined @return int days */
    public function retention(string $which): int
    {
        return (int) ($this->config['retention'][$which . '_days'] ?? 0);
    }
}
