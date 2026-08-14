<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\UserFlagTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserFlagTitleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'titles' => UserFlagTitle::query()
                ->orderBy('kind')
                ->orderBy('title')
                ->get(['id', 'kind', 'title']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([UserFlagTitle::KIND_GREEN, UserFlagTitle::KIND_RED])],
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('user_flag_titles', 'title')->where(fn ($query) => $query->where('kind', $request->input('kind'))),
            ],
        ]);

        $title = UserFlagTitle::create([
            'kind' => $validated['kind'],
            'title' => trim($validated['title']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Titre cree.',
            'title' => $title,
        ], 201);
    }

    public function update(Request $request, UserFlagTitle $flagTitle): JsonResponse
    {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('user_flag_titles', 'title')
                    ->where(fn ($query) => $query->where('kind', $flagTitle->kind))
                    ->ignore($flagTitle->id),
            ],
        ]);

        $flagTitle->update(['title' => trim($validated['title'])]);

        return response()->json([
            'success' => true,
            'message' => 'Titre mis a jour.',
            'title' => $flagTitle->fresh(),
        ]);
    }
}
