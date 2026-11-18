<?php

namespace App\Http\Controllers;

use App\Actions\StartGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The generate form and the user's generations.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Generations', [
            'generations' => $request->user()->generations()->latest()->latest('id')->paginate(12),
            'sizes' => Generation::SIZES,
            'qualities' => Generation::QUALITIES,
        ]);
    }

    /**
     * What a generation would cost, shown live while the user fills in the form.
     */
    public function estimate(Request $request, PixelforgeClient $pixelforge): JsonResponse
    {
        $input = $request->validate([
            'characters' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        return response()->json(Pricing::estimate(
            $pixelforge->pricing(),
            (int) $input['characters'],
            $input['size'],
            $input['quality'],
            (int) $input['reference_images'],
        ));
    }

    public function store(StoreGenerationRequest $request, StartGeneration $start): RedirectResponse
    {
        $start(
            $request->user(),
            $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            $request->validated('reference_images', []),
        );

        return to_route('dashboard'); // page 1, where the new generation is
    }
}
