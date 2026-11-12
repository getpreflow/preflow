<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Members\Admission\AcceptOnConfirm;

/**
 * config/members.php: every key optional, the defaults are ordinary values: the admission AcceptOnConfirm, the
 * package's own page layout, the mail settings of config/notify.php. The
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
    /** Throttle purposes (rows in the throttle table): sign-in, registration, forgot password, resend the confirmation. */
    public const string THROTTLE_LOGIN = 'members.login';
    public const string THROTTLE_REGISTER = 'members.register';
    public const string THROTTLE_FORGOT = 'members.forgot';
    public const string THROTTLE_RESEND = 'members.resend';
    /** The storage alias of the member type; without it the type lives on "default". */
    public const string STORAGE = 'members';

    private const array DEFAULTS = [
        'path' => '/members',
        'landing' => null,
        'register' => null,
        // the package's own standalone page; a site names its layout (Folio's members admin names Folio's)
        'layout' => '@members/_layout.twig',
        // null: the mail settings of config/notify.php (its layout, the neutral accent)
        'mail' => ['from_name' => null, 'layout' => null, 'accent' => null],
        'admission' => AcceptOnConfirm::class,
        // [attempts, seconds] per throttle
        'throttle' => ['login' => [10, 900], 'register' => [5, 3600], 'forgot' => [5, 3600], 'resend' => [3, 3600]],
        'password_min' => 10,
        'confirm_ttl' => 86400,
        'reset_ttl' => 3600,
        'retention' => ['unconfirmed_days' => 7, 'declined_days' => 30],
        // the consent's privacy link: one URL, or locale => URL; '' = the consent without a link
        'privacy_url' => '',
        // Where "next" may send a member besides the member pages: site path prefixes ("/games/"), in every locale.
        'next' => ['allow' => []],
        // The owner's settings (ConfigMembersSettings; Folio's members admin keeps them under Settings → Members).
        'registration_open' => true,
        'notice_email' => null,
        // one text, or locale => text; empty means the lang files' (members.mail.accepted.body / declined.body)
        'accept_message' => null,
        'decline_message' => null,
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    /** Whether config/members.php itself names the layout. */
    private readonly bool $layoutSet;

    /**
     * @param array<string, mixed> $config the array config/members.php returns; nested groups merge key by key
     * @param array<string, mixed> $defaults a package's defaults ({@see MembersDefaults}), under the site's keys
     * @param ?string $layoutCandidate a package's layout that {@see layout()} takes when the engine has it and the site names none
     */
    public function __construct(array $config = [], array $defaults = [], private readonly ?string $layoutCandidate = null)
    {
        $this->layoutSet = array_key_exists('layout', $config);
        $config += $defaults;
        $merged = $config + self::DEFAULTS;
        foreach (['mail', 'throttle', 'retention', 'next'] as $group) {
            $merged[$group] = (is_array($config[$group] ?? null) ? $config[$group] : []) + self::DEFAULTS[$group];
        }
        // The member pages own everything below the path: at the site root they would shadow the site's pages.
        if (!is_string($merged['path']) || trim($merged['path'], '/') === '') {
            throw new \InvalidArgumentException('config/members.php path must be a page below the site root, like "/members"; "' . (is_string($merged['path']) ? $merged['path'] : get_debug_type($merged['path'])) . '" is not.');
        }
        $this->config = $merged;
    }

    /**
     * A missing file means all defaults.
     *
     * @param array<string, mixed> $defaults a package's defaults, under the file's keys
     */
    public static function fromFile(string $file, array $defaults = [], ?string $layoutCandidate = null): self
    {
        $config = is_file($file) ? require $file : [];

        return new self(is_array($config) ? $config : [], $defaults, $layoutCandidate);
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

    /**
     * Where the pages link for registering; {path}/register (the package's page) unless set.
     * A site whose members home shows the form itself sets it to that page; the form still
     * posts to {path}/register, and GET {path}/register redirects there.
     */
    public function register(): string
    {
        $register = $this->config['register'];

        return is_string($register) && $register !== '' ? $register : $this->path() . '/register';
    }

    /**
     * The privacy page the register form's consent links, in $locale: the one URL, or the
     * locale's entry of the map (else $default's); '' when there is none.
     */
    public function privacyUrl(string $locale, string $default): string
    {
        $url = $this->config['privacy_url'];
        if (is_array($url)) {
            $url = $url[$locale] ?? $url[$default] ?? '';
        }

        return is_string($url) ? $url : '';
    }

    /**
     * The path prefixes next.allow adds to the member pages as places "next" may lead (each
     * ends at a path segment: "/games" and "/games/" both allow "/games" and "/games/catan",
     * not "/gamesfoo"). Each must be a path of this site: one leading slash, no scheme, no
     * second slash at the start, no backslash, no dot segment.
     *
     * @return list<string>
     * @throws \InvalidArgumentException for an entry that is no such path
     */
    public function nextAllow(): array
    {
        $allow = $this->config['next']['allow'] ?? [];
        if (!is_array($allow)) {
            throw new \InvalidArgumentException('config/members.php next.allow must be a list of paths like "/games/".');
        }
        $paths = [];
        foreach ($allow as $path) {
            if (!is_string($path) || preg_match('#^/(?!/)[A-Za-z0-9._~%/-]*$#D', $path) !== 1
                || preg_match('#(^|/)\.\.?(/|$)|//|\\\\#', rawurldecode($path)) === 1) {
                throw new \InvalidArgumentException('config/members.php next.allow: ' . (is_string($path) ? '"' . $path . '"' : get_debug_type($path)) . ' is no path of this site; each entry is a path prefix like "/games/".');
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * The page layout the member templates extend: the site's, else the package's candidate when
     * $engine has that template (asked now, so the order the providers boot in does not matter),
     * else the default.
     */
    public function layout(?\Preflow\View\TemplateEngineInterface $engine = null): string
    {
        if (!$this->layoutSet && $this->layoutCandidate !== null && $engine?->exists($this->layoutCandidate) === true) {
            return $this->layoutCandidate;
        }

        return (string) $this->config['layout'];
    }

    public function mailFromName(): ?string
    {
        $name = $this->config['mail']['from_name'];

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** The HTML mail template of the site; null for notify's (config/notify.php mail.layout, else its neutral design). */
    public function mailLayout(): ?string
    {
        $layout = $this->config['mail']['layout'];

        return is_string($layout) && $layout !== '' ? $layout : null;
    }

    /** The accent colour of the HTML mails; null for notify's (config/notify.php mail.accent, else the neutral #1a1a1a). */
    public function accent(): ?string
    {
        $accent = $this->config['mail']['accent'];

        return is_string($accent) && $accent !== '' ? $accent : null;
    }

    /** A key of config/members.php as given (or its default): the owner's settings, read by ConfigMembersSettings. */
    public function value(string $key): mixed
    {
        return $this->config[$key] ?? null;
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
