<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = Profile::query()
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Profile $profile) => $this->mapProfile($profile));

        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('label')
            ->orderBy('code')
            ->get(['id', 'code', 'label', 'group']);

        return response()->json([
            'profiles' => $profiles,
            'permissions' => $permissions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile_name' => ['required', 'string', 'max:255', 'unique:profiles,profile_name'],
        ]);

        $profile = Profile::create([
            'profile_name' => trim($data['profile_name']),
            'sort_order' => (Profile::max('sort_order') ?? 0) + 1,
        ]);

        $profile->setAttribute('users_count', 0);

        return response()->json([
            'profile' => $this->mapProfile($profile),
            'message' => 'Profil créé avec succès.',
        ], 201);
    }

    public function update(Request $request, Profile $profile): JsonResponse
    {
        $this->ensureMutable($profile);

        $data = $request->validate([
            'profile_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('profiles', 'profile_name')->ignore($profile->id),
            ],
        ]);

        $profile->update(['profile_name' => trim($data['profile_name'])]);
        $profile->loadCount('users');

        return response()->json([
            'profile' => $this->mapProfile($profile),
            'message' => 'Profil modifié avec succès.',
        ]);
    }

    public function destroy(Profile $profile): JsonResponse
    {
        $this->ensureMutable($profile);

        if ($profile->users()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer le profil : des utilisateurs sont assignés à ce profil.',
            ], 403);
        }

        $profile->delete();

        return response()->json(['message' => 'Profil supprimé avec succès.']);
    }

    public function permissions(Profile $profile): JsonResponse
    {
        return response()->json([
            'permission_ids' => $profile->permissions()->pluck('permissions.id')->values(),
        ]);
    }

    public function syncPermissions(Request $request, Profile $profile): JsonResponse
    {
        $this->ensureMutable($profile);

        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $profile->permissions()->sync($data['permissions']);

        return response()->json(['message' => 'Permissions enregistrées avec succès.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $profiles = DB::transaction(function () use ($data) {
            $profiles = Profile::query()
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $currentIndex = $profiles->search(fn (Profile $profile) => $profile->id === (int) $data['profile_id']);
            $targetIndex = $data['direction'] === 'up' ? $currentIndex - 1 : $currentIndex + 1;

            if ($currentIndex === false || ! $profiles->has($targetIndex)) {
                abort(422, 'Impossible de déplacer le profil dans cette direction.');
            }

            $current = $profiles->get($currentIndex);
            $target = $profiles->get($targetIndex);
            $currentOrder = $current->sort_order;

            $current->update(['sort_order' => $target->sort_order]);
            $target->update(['sort_order' => $currentOrder]);

            return Profile::query()
                ->withCount('users')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Profile $profile) => $this->mapProfile($profile));
        });

        return response()->json([
            'profiles' => $profiles,
            'message' => 'Ordre des profils mis à jour avec succès.',
        ]);
    }

    private function ensureMutable(Profile $profile): void
    {
        abort_if(strcasecmp($profile->profile_name, 'Admin') === 0, 403, 'Le profil Admin est protégé.');
    }

    private function mapProfile(Profile $profile): array
    {
        return [
            'id' => $profile->id,
            'profile_name' => $profile->profile_name,
            'sort_order' => $profile->sort_order,
            'users_count' => (int) ($profile->users_count ?? 0),
            'is_protected' => strcasecmp($profile->profile_name, 'Admin') === 0,
        ];
    }
}
