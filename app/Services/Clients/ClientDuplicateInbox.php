<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientDuplicateReview;

class ClientDuplicateInbox
{
    public function __construct(private ClientDuplicateDetector $detector) {}

    public function clients(): array
    {
        return Client::with('webshopData')->orderBy('id')->get()->map(fn ($client) => $this->present($client))->all();
    }

    public function present(Client $client): array
    {
        return array_merge($client->only(['id', 'first_name', 'last_name', 'email', 'phone_number', 'fixed_phone_number', 'address', 'city_id', 'ice', 'description', 'image_id', 'source', 'updated_at']), [
            'name' => trim($client->first_name . ' ' . $client->last_name),
            'validated' => (bool) $client->validated,
            'has_account' => (bool) $client->password,
            'has_webshop_profile' => (bool) $client->webshopData,
        ]);
    }

    public function check(array $data): array
    {
        $matches = [];
        foreach ($this->clients() as $client) {
            foreach ($this->detector->detect([array_merge($data, ['id' => 0]), $client]) as $match) $matches[] = $match;
        }
        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $matches;
    }

    public function scan(): void
    {
        $activeIds = [];
        foreach ($this->detector->detect($this->clients()) as $match) {
            $review = ClientDuplicateReview::firstOrCreate([
                'client_a_id' => $match['clients'][0]['id'], 'client_b_id' => $match['clients'][1]['id'],
            ], $match + ['status' => 'pending']);
            if ($review->status === 'pending') $review->update($match);
            $activeIds[] = $review->id;
        }
        ClientDuplicateReview::where('status', 'pending')->whereNotIn('id', $activeIds)->delete();
        ClientDuplicateReview::where('status', 'pending')->where(function ($query) {
            $query->whereNotIn('client_a_id', Client::select('id'))->orWhereNotIn('client_b_id', Client::select('id'));
        })->delete();
    }
}
