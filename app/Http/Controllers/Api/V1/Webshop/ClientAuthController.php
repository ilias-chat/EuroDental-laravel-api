<?php

namespace App\Http\Controllers\Api\V1\Webshop;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WebshopData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClientAuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $client = Client::where('email', strtolower(trim($credentials['email'])))->first();
        if (! $client || ! $client->password || ! Hash::check($credentials['password'], $client->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        return response()->json([
            'token' => $client->createToken('webshop')->plainTextToken,
            'client' => $this->present($client),
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:6'],
            'address' => ['nullable', 'string', 'max:500'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'phone_number' => ['nullable', 'string', 'max:20'],
        ]);

        $client = Client::where('email', strtolower(trim($data['email'])))->first();
        if ($client?->password) {
            return response()->json(['message' => 'An account already exists for this email.'], 409);
        }

        $client ??= new Client(['email' => strtolower(trim($data['email']))]);
        $client->fill([
            'password' => Hash::make($data['password']),
            'source' => 'webshop',
            'validated' => false,
            'handled' => false,
        ])->save();

        WebshopData::updateOrCreate(
            ['client_id' => $client->id],
            [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'address' => $data['address'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'validated' => false,
            ]
        );

        return response()->json([
            'token' => $client->createToken('webshop')->plainTextToken,
            'client' => $this->present($client->fresh()),
        ], 201);
    }

    public function me(Request $request)
    {
        return response()->json(['client' => $this->present($request->user())]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out']);
    }

    private function present(Client $client): array
    {
        $client->loadMissing('webshopData', 'city');
        return [
            'id' => $client->id,
            'email' => $client->email,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'phone_number' => $client->phone_number,
            'address' => $client->address,
            'city_id' => $client->city_id,
            'city' => $client->city?->name,
        ];
    }
}
