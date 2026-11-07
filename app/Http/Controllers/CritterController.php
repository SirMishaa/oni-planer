<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\CritterFamilyData;
use App\Services\Critters\CritterPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CritterController
{
    public function __construct(private CritterPresenter $presenter)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Critters/Index', [
            'families' => $this->presenter->familyList(),
        ]);
    }

    public function show(Request $request, string $family): Response
    {
        $data = $this->presenter->family($family);
        throw_if(!$data instanceof CritterFamilyData, NotFoundHttpException::class);

        return Inertia::render('Critters/Show', [
            'family' => $data,
            'selected' => $request->query('variant'),
        ]);
    }
}
