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
 * visitor who is not signed in, or not admitted, goes to the login; staff who may access the
 * area (and so are sent here by the login) get the greeting without the account links.
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
        if (!$this->viewer->canAccess()) {
            return $this->view->redirect($this->links->local(Input::locale($request, $this->locales), '/login'));
        }
        // Staff may access the members area without a member account: the greeting, without account and sign-out.
        $member = $this->viewer->member();

        return $this->view->render('landing', [
            'name' => $this->viewer->name(),
            'email' => (string) ($member?->get('email') ?? ''),
            'has_account' => $member !== null,
            'notice' => Input::flash($request, Input::NOTICE),
        ], $request);
    }
}
