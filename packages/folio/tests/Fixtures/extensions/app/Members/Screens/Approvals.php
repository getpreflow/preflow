<?php

declare(strict_types=1);

namespace App\Folio\Members\Screens;

use Nyholm\Psr7\Response;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Post;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Preflow\Folio\Extension\Attributes\FolioScreen('approvals', 'Approvals', permission: 'members.approve')]
final class Approvals
{
    #[Get('/')]
    public function index(ServerRequestInterface $request): ScreenView
    {
        return new ScreenView('@members/approvals.twig', ['pending' => ['m1', 'm2']]);
    }

    #[Post('/{id}/approve')]
    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(302, ['Location' => '/folio/approvals?approved=' . $request->getAttribute('id')]);
    }
}
