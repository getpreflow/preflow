<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class AuthExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?SessionInterface $session = null,
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'auth_user',
                callable: fn () => $this->authManager->user(),
                isSafe: false,
            ),
            new TemplateFunctionDefinition(
                name: 'auth_check',
                callable: fn () => $this->authManager->user() !== null,
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'csrf_token',
                callable: fn () => $this->csrfValue(),
                isSafe: false, // raw string, needs escaping in HTML
            ),
            new TemplateFunctionDefinition(
                name: 'csrf_field',
                callable: fn () => $this->csrfField(),
                isSafe: true, // pre-escaped HTML
            ),
            new TemplateFunctionDefinition(
                name: 'flash',
                callable: fn (string $key, mixed $default = null) => $this->session?->getFlash($key, $default),
                isSafe: false,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    private function csrfValue(): string
    {
        if ($this->session === null) {
            return '';
        }
        $token = CsrfToken::fromSession($this->session);
        return $token?->getValue() ?? '';
    }

    private function csrfField(): string
    {
        $value = $this->csrfValue();
        if ($value === '') {
            return '';
        }
        $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $escaped . '">';
    }
}
