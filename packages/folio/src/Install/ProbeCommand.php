<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Rbac\DeclaredPermissions;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffRefused;
use Preflow\Rbac\StaffManagers;
use Preflow\Data\DataManager;

/**
 * folio:install's questions to the booted app, asked in a fresh PHP process (AppConsole), so the
 * app boots with the config files on disk. Not a command of its own: AppConsole registers it in
 * the child only, and it answers with one line, MARK followed by JSON.
 *
 * - `editor`: what the starting editor role may get, declared permissions only (rbac's
 *   DeclaredPermissions decides): folio.dashboard, the asset library's view, upload and edit, and
 *   "<type>.*" for each content type of the app's own;
 * - `state [<email>]`: the app's address, Folio's shortest password, how many staff accounts
 *   there are (null while rbac, its staff type or its tables are missing) and whether <email> has one;
 * - `admin`: creates the first admin from {"email", "name", "password"|null} on stdin (StaffAccounts:
 *   the rules of every save; the password checked before the account is made).
 *
 * @since 0.85.0
 */
final class ProbeCommand implements CommandInterface
{
    public const string NAME = 'folio:install:probe';
    public const string MARK = '@folio-install ';

    /** What the editor role gets besides the content types, as starter-folio's editor has it. */
    private const array EDITOR = ['folio.dashboard', 'assets.view', 'assets.upload', 'assets.edit'];

    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    public function getName(): string { return self::NAME; }
    public function getDescription(): string { return 'folio:install\'s questions to the booted app (internal)'; }

    public function execute(array $args): int
    {
        $root = $this->basePath ?? (getcwd() ?: '.');
        try {
            $answer = match ($args[0] ?? '') {
                'editor' => $this->editor($this->boot($root)),
                'state' => $this->state($root, $args[1] ?? null),
                'admin' => $this->admin($this->boot($root), (string) stream_get_contents(STDIN)),
                default => ['error' => 'Unknown probe "' . ($args[0] ?? '') . '".'],
            };
        } catch (\Throwable $e) {
            $answer = ['error' => $e->getMessage()];
        }
        echo self::MARK . json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return isset($answer['error']) ? 1 : 0;
    }

    private function boot(string $root): Application
    {
        $app = Application::create($root);
        $app->boot();

        return $app;
    }

    /** @return array<string, mixed> */
    private function editor(Application $app): array
    {
        $c = $app->container();
        $registry = $c->get(Access::class)->registry();
        $candidates = self::EDITOR;
        foreach ($c->get(TypeCatalog::class)->all() as $listing) {
            // The app's own content types; never a user type (staff, members) or an extension's.
            if ($listing->group === 'site' && $listing->users === null) {
                $candidates[] = $listing->key . '.*';
            }
        }
        $undeclared = DeclaredPermissions::undeclared($registry, $candidates);

        return ['editor' => array_values(array_diff($candidates, $undeclared)), 'left_out' => $undeclared];
    }

    /** @return array<string, mixed> */
    private function state(string $root, ?string $email): array
    {
        $app = Application::create($root);
        $folio = $app->configFile('folio');
        $answer = [
            'url' => $app->site()->origin,
            'password_min' => max(1, (int) ((is_array($folio) ? $folio : [])['password_min'] ?? 10)),
            'staff' => null,
            'exists' => false,
        ];
        try {
            $app->boot();
        } catch (\Throwable $e) {
            return $answer + ['boot' => $e->getMessage()];
        }
        $accounts = $this->accounts($app);
        if ($accounts !== null) {
            $answer['staff'] = count($accounts->all());
            $answer['exists'] = $email !== null && $accounts->find($email) !== null;
            $answer['password_min'] = max($answer['password_min'], $accounts->passwordMin());
        }

        return $answer;
    }

    /** @return array<string, mixed> */
    private function admin(Application $app, string $input): array
    {
        $data = json_decode($input, true);
        if (!is_array($data) || !is_string($data['email'] ?? null)) {
            return ['error' => 'No admin on stdin.'];
        }
        $accounts = $this->accounts($app);
        if ($accounts === null) {
            return ['error' => 'The staff accounts are not ready (preflow/rbac, rbac:install).'];
        }
        if ($accounts->find($data['email']) !== null) {
            return ['status' => 'exists'];
        }
        $password = is_string($data['password'] ?? null) ? $data['password'] : null;
        $folio = $app->configFile('folio');
        $min = max(1, (int) ((is_array($folio) ? $folio : [])['password_min'] ?? 10));
        try {
            if ($password !== null) {
                if (mb_strlen($password) < $min) {
                    return ['status' => 'refused', 'message' => "The password is too short: at least {$min} characters."];
                }
                $accounts->checkPassword($password);
            }
            $staff = $accounts->create($data['email'], (string) ($data['name'] ?? ''), ['admin']);
            if ($password !== null) {
                $accounts->setPassword($staff, $password);
            }
        } catch (StaffRefused $e) {
            return ['status' => 'refused', 'message' => $e->getMessage()];
        }

        return ['status' => 'created', 'email' => (string) $staff->get('email')];
    }

    /** The app's staff accounts, null until rbac, its staff type and its tables are there. */
    private function accounts(Application $app): ?StaffAccounts
    {
        $c = $app->container();
        if (!$c->bound(StaffAccounts::class) || !$c->get(RoleStore::class)->installed()) {
            return null;
        }
        $accounts = $c->get(StaffAccounts::class);
        if (!$accounts->enabled() || !(new StaffManagers($c->get(DataManager::class)))->hasTable()) {
            return null;
        }

        return $accounts;
    }
}
