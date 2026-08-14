<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFlagEvent;
use App\Models\UserFlagTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserFlagController extends Controller
{
    public function index(User $user): JsonResponse
    {
        $flags = UserFlagEvent::query()
            ->where('user_id', $user->id)
            ->with(['creator', 'retiredBy', 'flagTitle'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'flags' => $flags->map(fn (UserFlagEvent $flag) => $this->formatFlag($flag))->values(),
            'green_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_GREEN),
            'red_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_RED),
            'flag_titles' => $this->flagTitles(),
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([UserFlagEvent::KIND_GREEN, UserFlagEvent::KIND_RED])],
            'flag_title_id' => [
                'required',
                'integer',
                Rule::exists('user_flag_titles', 'id')->where(fn ($query) => $query->where('kind', $request->input('kind'))),
            ],
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $note = trim($validated['note']);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => ['La note est obligatoire.']]);
        }

        $flag = DB::transaction(fn () => UserFlagEvent::create([
            'user_id' => $user->id,
            'kind' => $validated['kind'],
            'user_flag_title_id' => $validated['flag_title_id'],
            'note' => $note,
            'created_by' => $request->user()->id,
        ]));

        $flag->load(['creator', 'retiredBy', 'flagTitle']);

        return response()->json([
            'success' => true,
            'message' => 'Drapeau ajoute.',
            'flag' => $this->formatFlag($flag),
            'green_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_GREEN),
            'red_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_RED),
        ], 201);
    }

    public function update(Request $request, User $user, UserFlagEvent $flag): JsonResponse
    {
        $this->ensureFlagBelongsToUser($user, $flag);

        $validated = $request->validate([
            'flag_title_id' => [
                'required',
                'integer',
                Rule::exists('user_flag_titles', 'id')->where(fn ($query) => $query->where('kind', $flag->kind)),
            ],
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $note = trim($validated['note']);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => ['La note est obligatoire.']]);
        }

        $flag->update([
            'user_flag_title_id' => $validated['flag_title_id'],
            'note' => $note,
        ]);

        $flag->load(['creator', 'retiredBy', 'flagTitle']);

        return response()->json([
            'success' => true,
            'message' => 'Drapeau mis a jour.',
            'flag' => $this->formatFlag($flag),
            'green_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_GREEN),
            'red_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_RED),
        ]);
    }

    public function retire(Request $request, User $user, UserFlagEvent $flag): JsonResponse
    {
        $this->ensureFlagBelongsToUser($user, $flag);

        if ($flag->retired_at !== null) {
            return response()->json(['success' => false, 'message' => 'Ce drapeau est deja retire.'], 422);
        }

        $validated = $request->validate([
            'retired_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $retiredNote = trim($validated['retired_note'] ?? '');
        $flag->update([
            'retired_at' => now(),
            'retired_by' => $request->user()->id,
            'retired_note' => $retiredNote === '' ? null : $retiredNote,
        ]);

        $flag->load(['creator', 'retiredBy', 'flagTitle']);

        return response()->json([
            'success' => true,
            'message' => 'Drapeau retire.',
            'flag' => $this->formatFlag($flag),
            'green_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_GREEN),
            'red_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_RED),
        ]);
    }

    public function destroy(User $user, UserFlagEvent $flag): JsonResponse
    {
        $this->ensureFlagBelongsToUser($user, $flag);

        $flag->delete();

        return response()->json([
            'success' => true,
            'message' => 'Drapeau supprime.',
            'green_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_GREEN),
            'red_net' => UserFlagEvent::netForUser($user->id, UserFlagEvent::KIND_RED),
        ]);
    }

    private function flagTitles()
    {
        return UserFlagTitle::query()
            ->orderBy('kind')
            ->orderBy('title')
            ->get(['id', 'kind', 'title'])
            ->values();
    }

    private function ensureFlagBelongsToUser(User $user, UserFlagEvent $flag): void
    {
        if ((int) $flag->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    private function formatFlag(UserFlagEvent $flag): array
    {
        return [
            'id' => $flag->id,
            'kind' => $flag->kind,
            'flag_title_id' => $flag->user_flag_title_id,
            'flag_title' => $flag->flagTitle ? [
                'id' => $flag->flagTitle->id,
                'title' => $flag->flagTitle->title,
                'kind' => $flag->flagTitle->kind,
            ] : null,
            'note' => $flag->note,
            'created_at' => $flag->created_at?->toIso8601String(),
            'creator' => $flag->creator ? [
                'id' => $flag->creator->id,
                'name' => trim($flag->creator->first_name.' '.$flag->creator->last_name),
            ] : null,
            'retired_at' => $flag->retired_at?->toIso8601String(),
            'retired_note' => $flag->retired_note,
            'retired_by' => $flag->retiredBy ? [
                'id' => $flag->retiredBy->id,
                'name' => trim($flag->retiredBy->first_name.' '.$flag->retiredBy->last_name),
            ] : null,
            'is_active' => $flag->retired_at === null,
        ];
    }
}
