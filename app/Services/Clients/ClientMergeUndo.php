<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientDuplicateReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientMergeUndo
{
    public const TABLES = ['orders', 'tasks', 'invoices', 'purchases', 'proposed_tasks', 'webshop_data'];
    public const PROFILE_FIELDS = ['first_name', 'last_name', 'email', 'phone_number', 'fixed_phone_number', 'address', 'city_id', 'ice', 'description', 'image_id', 'validated', 'updated_at'];

    public function fingerprint(array $attributes): string
    {
        ksort($attributes);
        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function records(string $table, array $ids): array
    {
        if (! $ids) return [];
        return DB::table($table)->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($row) => [
            'id' => $row->id, 'fingerprint' => $this->fingerprint((array) $row),
        ])->all();
    }

    public function preview(ClientDuplicateReview $review): array
    {
        return DB::transaction(function () use ($review) {
            [$audit, $data] = $this->inspect($review->id);
            return [
                'audit_id' => $audit->id,
                'source_client_id' => $audit->source_client_id,
                'survivor_client_id' => $audit->survivor_client_id,
                'source_name' => trim(($data['source']['first_name'] ?? '') . ' ' . ($data['source']['last_name'] ?? '')),
                'counts' => array_map('count', $data['records']),
            ];
        });
    }

    public function undo(ClientDuplicateReview $review, int $auditId, int $actorId): void
    {
        DB::transaction(function () use ($review, $auditId, $actorId) {
            [$audit, $data, $survivor, $lockedReview] = $this->inspect($review->id);
            abort_unless($audit->id === $auditId, 409, 'La fusion a change. Rouvrez son annulation.');
            $source = $data['source'];
            // Credentials and revoked sessions are never reconstructed from audit history.
            unset($source['password'], $source['remember_token']);
            DB::table('clients')->insert($source);
            foreach ($data['records'] as $table => $records) {
                if ($records) DB::table($table)->whereIn('id', array_column($records, 'id'))->update(['client_id' => $audit->source_client_id]);
            }
            if ($data['webshop_before']) {
                $profile = $data['webshop_before'];
                DB::table('webshop_data')->where('id', $profile['id'])->update($profile);
            }
            $before = json_decode($audit->before, true, 512, JSON_THROW_ON_ERROR);
            $original = collect($before)->first(fn ($client) => (int) $client['id'] === $audit->survivor_client_id);
            $survivorValues = array_intersect_key($original, array_flip(self::PROFILE_FIELDS));
            DB::table('clients')->where('id', $survivor->id)->update($survivorValues);
            DB::table('client_merge_audits')->where('id', $audit->id)->update(['undone_at' => now(), 'undone_by' => $actorId]);
            $lockedReview->update(['status' => 'pending', 'survivor_client_id' => null, 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
            app(ClientDuplicateInbox::class)->scan();
        }, 3);
    }

    private function inspect(int $reviewId): array
    {
        $review = ClientDuplicateReview::lockForUpdate()->findOrFail($reviewId);
        abort_unless($review->status === 'merged', 409, 'Cette fusion n est plus active.');
        $audit = DB::table('client_merge_audits')->where('review_id', $reviewId)->orderByDesc('id')->lockForUpdate()->first();
        abort_unless($audit && $audit->undo_data && ! $audit->undone_at, 409, 'Cette ancienne fusion ne contient pas l historique necessaire a son annulation.');
        $audit->id = (int) $audit->id;
        $audit->source_client_id = (int) $audit->source_client_id;
        $audit->survivor_client_id = (int) $audit->survivor_client_id;
        $data = json_decode($audit->undo_data, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($data['version'] ?? null) === 1, 409, 'Historique de fusion incompatible.');
        abort_if(Client::whereKey($audit->source_client_id)->lockForUpdate()->exists(), 409, 'L identifiant du client a restaurer est deja utilise.');
        $survivor = Client::whereKey($audit->survivor_client_id)->lockForUpdate()->first();
        abort_unless($survivor && hash_equals($data['survivor_fingerprint'], $this->fingerprint($survivor->getAttributes())), 409, 'Le client conserve a ete modifie depuis la fusion. Une verification manuelle est necessaire.');
        $before = json_decode($audit->before, true, 512, JSON_THROW_ON_ERROR);
        foreach (array_merge($before, [$data['source']]) as $client) {
            foreach (['city_id' => 'cities', 'image_id' => 'images'] as $field => $table) {
                if (! empty($client[$field])) abort_unless(Schema::hasTable($table) && DB::table($table)->where('id', $client[$field])->exists(), 409, 'Une ville ou une image necessaire a la restauration n existe plus.');
            }
        }
        foreach ($data['records'] as $table => $records) {
            abort_unless(in_array($table, self::TABLES, true), 409, 'Historique de transfert invalide.');
            if (! $records) continue;
            abort_unless(Schema::hasTable($table), 409, 'Une table necessaire a la restauration n existe plus.');
            $rows = DB::table($table)->whereIn('id', array_column($records, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($records as $record) {
                $row = $rows->get($record['id']);
                abort_unless($row && (int) $row->client_id === $audit->survivor_client_id && hash_equals($record['fingerprint'], $this->fingerprint((array) $row)), 409, 'Un element transfere a ete modifie, supprime ou reaffecte (' . $table . ' #' . $record['id'] . '). Une verification manuelle est necessaire.');
            }
        }
        return [$audit, $data, $survivor, $review];
    }
}
