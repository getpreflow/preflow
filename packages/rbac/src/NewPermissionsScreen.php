<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Nyholm\Psr7\Response;
use Preflow\Core\Container\Container;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\Attributes\FolioScreen;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Rbac\Types\RoleRules;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Preflow\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The actions of the new-permissions notice on the Roles list (NewPermissions), outside the
 * sidebar, for those who may edit roles. Dismiss and "Add to role" take the line's names[]
 * (only the ones still new count) and make them known for good; "Add to role" first gives
 * them to the chosen role (role = its id) and saves it as the role editor does: every
 * #[BeforeSave] of the type (RoleRules among them: delegation, lock-out), the type's validation,
 * the save, every #[AfterSave]. A refusal changes nothing. Each answers with a redirect to the
 * Roles list and a flash message, also when the new permissions can't be read (a site not
 * migrated) and when the save or an #[AfterSave] fails (logged).
 *
 * @since 0.53
 */
#[FolioScreen(path: self::PATH, label: 'rbac.new.title', permission: 'rbac_role.edit', nav: false)]
final class NewPermissionsScreen
{
    public const string PATH = 'rbac/new-permissions';

    public function __construct(
        private readonly NewPermissions $permissions,
        private readonly DataManager $dm,
        private readonly RoleRules $rules,
        private readonly Messages $messages,
        private readonly Container $container,
    ) {}

    /** No page of its own: the notice lives on the Roles list. */
    #[Get('/')]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return self::roles($request);
    }

    #[Post('/dismiss')]
    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $names = $this->requested($request);
            $this->permissions->markKnown($names);
        } catch (\Throwable $e) {
            return $this->unavailable($request, $e);
        }

        return self::back($request, 'success', $this->messages->get('new.dismissed'));
    }

    #[Post('/grant')]
    public function grant(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $names = $this->requested($request);
        } catch (\Throwable $e) {
            return $this->unavailable($request, $e);
        }
        $body = (array) $request->getParsedBody();
        $id = is_string($body['role'] ?? null) ? $body['role'] : '';
        $role = $id === '' ? null : $this->dm->findType('rbac_role', $id);
        if (!$role instanceof DynamicRecord) {
            return self::back($request, 'error', $this->messages->get('new.no_role'));
        }
        if ($names === []) {
            return self::roles($request); // dismissed or added meanwhile
        }

        $current = RoleList::normalize($role->get('permissions'));
        $role->set('permissions', [...$current, ...array_values(array_diff($names, $current))]);
        $customizations = $this->container->bound(Customizations::class) ? $this->container->get(Customizations::class) : null;
        try {
            // As the role editor saves (AdminController): the type's #[BeforeSave] hooks, then validation.
            $customizations !== null ? $customizations->runHooks(BeforeSave::class, $role) : $this->rules->beforeSave($role);
            if ($this->container->bound(RecordValidator::class)) {
                $this->container->get(RecordValidator::class)->validate($role);
            }
        } catch (ValidationException $e) {
            return self::back($request, 'error', self::firstMessage($e) ?? $e->getMessage());
        } catch (Refused $e) {
            return self::back($request, 'error', $e->getMessage());
        }
        try {
            $this->dm->saveType($role, false);
            $this->permissions->markKnown($names);
            $customizations?->runHooks(AfterSave::class, $role);
        } catch (\Throwable $e) {
            // The storage or a site's #[AfterSave] failed: a message instead of a 500, the cause in the log.
            error_log('rbac: add to role failed: ' . $e->getMessage());

            return self::back($request, 'error', $this->messages->get('new.failed'));
        }
        $label = $this->messages->roleLabel((string) $role->get('population'), (string) $role->get('name'), (string) $role->get('label'));

        return self::back($request, 'success', $this->messages->get('new.added', ['role' => $label]));
    }

    /**
     * The posted names that are new now, in their order, each once.
     *
     * @return list<string>
     */
    private function requested(ServerRequestInterface $request): array
    {
        $posted = ((array) $request->getParsedBody())['names'] ?? [];
        $names = array_filter(is_array($posted) ? $posted : [], 'is_string');

        return array_values(array_unique(array_intersect($names, $this->permissions->fresh())));
    }

    private function unavailable(ServerRequestInterface $request, \Throwable $e): ResponseInterface
    {
        error_log('rbac: new permissions unavailable: ' . $e->getMessage());

        return self::back($request, 'error', $this->messages->get('new.unavailable'));
    }

    private static function firstMessage(ValidationException $e): ?string
    {
        foreach ($e->errors() as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return null;
    }

    private static function back(ServerRequestInterface $request, string $type, string $message): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash($type, $message);
        }

        return self::roles($request);
    }

    /** To the Roles list: the prefix is the request's path before this screen's. */
    private static function roles(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $at = strpos($path, '/' . self::PATH);
        $prefix = $at === false ? '' : substr($path, 0, $at);

        return new Response(302, ['Location' => $prefix . '/rbac_role']);
    }
}
