<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Critters\CritterPresenter;
use Inertia\Inertia;
use Inertia\Response;

final class RanchController
{
    public function __construct(private readonly CritterPresenter $presenter) {}

    public function index(): Response
    {
        return Inertia::render('Ranch/Index', [
            'catalog' => $this->presenter->catalog(),
        ]);
    }
}
