<?php

declare(strict_types=1);

namespace App\Folio\Demo\Screens;

use Preflow\Data\DataManager;
use Preflow\Folio\Extension\Attributes\FolioScreen;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\Routing\Attributes\Get;

#[FolioScreen('demo', 'Demo overview', icon: 'ui-file', permission: 'demo.view')]
final class Overview
{
    public function __construct(private readonly DataManager $data) {}

    #[Get('/')]
    public function index(): ScreenView
    {
        return new ScreenView('@demo/overview.twig', [
            'notes' => $this->data->queryType('note')->get()->items(),
        ]);
    }
}
