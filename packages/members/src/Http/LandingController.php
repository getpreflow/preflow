<?php

declare(strict_types=1);

namespace Preflow\Members\Http;

use Preflow\Data\Locales;
use Preflow\Members\Links;
use Preflow\Members\MembersView;
use Preflow\Members\Viewer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The default members home (GET {path}): a greeting with links to the account page and to sign
 * out. A site with its own page on that path never reaches it (MembersRoutes::landing()). A
 * visitor who is not signed in, or not admitted, goes to the login.
 */
final class LandingController
{
    public function __construct(
        private readonly MembersView $view,
        private readonly Viewer $viewer,
        private readonly Links $links,
        private readonly Locales $locales,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $member = $this->viewer->canAccess() ? $this->viewer->member() : null;
        if ($member === null) {
            return $this->view->redirect($this->links->local(Input::locale($request, $this->locales), '/login'));
        }

        return $this->view->render('landing', ['name' => (string) $member->get('name'), 'email' => (string) $member->get('email'), 'notice' => Input::flash($request, Input::NOTICE)], $request);
    }
}
