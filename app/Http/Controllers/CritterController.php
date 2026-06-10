<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Critters\CritterPresenter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CritterController
{
    public function __construct(private readonly CritterPresenter $presenter) {}

    public function index(): Response
    {
        return Inertia::render('Critters/Index', [
            'families' => $this->presenter->familyList(),
        ]);
    }

    public function show(string $family): Response
    {
        $data = $this->presenter->family($family);
        if ($data === null) {
            throw new NotFoundHttpException();
        }

        return Inertia::render('Critters/Show', [
            'family' => $data,
        ]);
    }
}
