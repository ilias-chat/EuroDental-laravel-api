<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDuplicateReview;
use App\Services\Clients\ClientDuplicateInbox;
use App\Services\Clients\ClientMergeUndo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ClientDuplicateController extends Controller
{
    private const FIELDS = ['first_name', 'last_name', 'email', 'phone_number', 'fixed_phone_number', 'address', 'city_id', 'ice', 'description', 'image_id'];
    private const TABLES = ['orders', 'tasks', 'invoices', 'purchases', 'proposed_tasks'];

    public function index(Request $request, ClientDuplicateInbox $inbox)
    {
        if (! ClientDuplicateReview::exists()) $inbox->scan();
        $status = in_array($request->status, ['ignored', 'merged'], true) ? $request->status : 'pending';
        $items = ClientDuplicateReview::where('status', $status)->orderByDesc('score')->orderBy('id')->paginate(20);
        $undoable = DB::table('client_merge_audits')->whereIn('review_id', $items->pluck('id'))->whereNotNull('undo_data')->whereNull('undone_at')->pluck('review_id')->all();
        $matches = collect($items->items())->map(fn ($item) => array_merge($item->toArray(), ['can_undo' => in_array($item->id, $undoable)]));
        return response()->json(['matches' => $matches, 'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()]]);
    }

    public function scan(ClientDuplicateInbox $inbox)
    {
        $inbox->scan();
        return response()->json(['message' => 'Analyse terminee.']);
    }

    public function check(Request $request, ClientDuplicateInbox $inbox)
    {
        $data = $request->validate(['first_name' => 'nullable|string|max:255', 'last_name' => 'nullable|string|max:255', 'email' => 'nullable|string|max:255', 'phone_number' => 'nullable|string|max:30', 'fixed_phone_number' => 'nullable|string|max:30']);
        return response()->json(['matches' => array_slice($inbox->check($data), 0, 10)]);
    }

    public function review(Request $request, ClientDuplicateReview $duplicate)
    {
        $data = $request->validate(['status' => 'required|in:ignored,pending']);
        abort_if($duplicate->status === 'merged', 409, 'Cette paire a deja ete fusionnee.');
        abort_unless(Client::whereIn('id', [$duplicate->client_a_id, $duplicate->client_b_id])->count() === 2, 409, 'Un de ces clients n existe plus.');
        $duplicate->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        return response()->json(['message' => $data['status'] === 'ignored' ? 'Paire ignoree.' : 'Paire remise a verifier.']);
    }

    public function preview(ClientDuplicateReview $duplicate, ClientDuplicateInbox $inbox)
    {
        abort_if($duplicate->status !== 'pending', 409, 'Cette paire n est plus a verifier.');
        $clients = Client::with('webshopData')->whereIn('id', [$duplicate->client_a_id, $duplicate->client_b_id])->orderBy('id')->get();
        abort_unless($clients->count() === 2, 409, 'Un client a deja ete supprime. Actualisez la liste.');
        return response()->json(['clients' => $clients->map(fn ($client) => $inbox->present($client) + ['counts' => $this->counts($client->id)])->values(), 'version' => $this->version($clients)]);
    }

    public function merge(Request $request, ClientDuplicateReview $duplicate, ClientDuplicateInbox $inbox)
    {
        $data = $request->validate(['survivor_id' => 'required|integer', 'version' => 'required|string', 'field_sources' => 'present|array', 'field_sources.*' => 'integer', 'confirmation' => 'required|in:fusionner']);
        $undo = app(ClientMergeUndo::class);
        $survivorId = DB::transaction(function () use ($request, $duplicate, $data, $inbox, $undo) {
            $review = ClientDuplicateReview::lockForUpdate()->findOrFail($duplicate->id);
            abort_if($review->status !== 'pending', 409, 'Cette paire a deja ete traitee.');
            $clients = Client::with('webshopData')->whereIn('id', [$review->client_a_id, $review->client_b_id])->orderBy('id')->lockForUpdate()->get();
            abort_unless($clients->count() === 2 && hash_equals($this->version($clients), $data['version']), 409, 'Les clients ont change. Rouvrez la fusion.');
            $survivor = $clients->firstWhere('id', (int) $data['survivor_id']);
            abort_unless($survivor, 422, 'Choisissez un des deux clients.');
            $source = $clients->first(fn ($client) => $client->id !== $survivor->id);
            if ($source->password) throw ValidationException::withMessages(['survivor_id' => 'Conservez le client qui possede le compte webshop. Deux comptes webshop distincts ne peuvent pas etre fusionnes.']);
            if ($source->webshopData && $survivor->webshopData) throw ValidationException::withMessages(['survivor_id' => 'Les deux clients ont des donnees webshop distinctes. Cette fusion demande une verification manuelle.']);
            $before = $clients->map(fn ($client) => $client->getAttributes())->map(fn ($attributes) => collect($attributes)->except(['password', 'remember_token'])->all())->all();
            $sourceOriginal = collect($source->getAttributes())->except(['password', 'remember_token'])->all();
            $webshopBefore = $source->webshopData ? (array) DB::table('webshop_data')->where('id', $source->webshopData->id)->lockForUpdate()->first() : null;
            $values = [];
            foreach (self::FIELDS as $field) {
                $owner = $clients->firstWhere('id', (int) ($data['field_sources'][$field] ?? $survivor->id));
                abort_unless($owner, 422, 'Choix de champ invalide.');
                $values[$field] = $owner->{$field};
            }
            if (! trim($values['first_name'] ?? '') || ! trim($values['last_name'] ?? '')) throw ValidationException::withMessages(['field_sources' => 'Le prenom et le nom sont obligatoires.']);
            if ($survivor->password && $values['email'] !== $survivor->email) throw ValidationException::withMessages(['field_sources' => 'Conservez l e-mail du compte webshop.']);
            $survivor->fill($values + ['validated' => $survivor->validated || $source->validated])->save();
            $counts = [];
            $records = [];
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $ids = DB::table($table)->where('client_id', $source->id)->orderBy('id')->lockForUpdate()->pluck('id')->all();
                    $counts[$table] = $ids ? DB::table($table)->whereIn('id', $ids)->update(['client_id' => $survivor->id]) : 0;
                    $records[$table] = $undo->records($table, $ids);
                }
            }
            if ($source->webshopData) $source->webshopData->update(['client_id' => $survivor->id]);
            $records['webshop_data'] = $webshopBefore ? $undo->records('webshop_data', [$webshopBefore['id']]) : [];
            $source->tokens()->delete();
            $undoData = ['version' => 1, 'source' => $sourceOriginal, 'survivor_fingerprint' => $undo->fingerprint($survivor->fresh()->getAttributes()), 'records' => $records, 'webshop_before' => $webshopBefore];
            DB::table('client_merge_audits')->insert(['review_id' => $review->id, 'undo_data' => json_encode($undoData, JSON_THROW_ON_ERROR), 'source_client_id' => $source->id, 'survivor_client_id' => $survivor->id, 'merged_by' => $request->user()->id, 'before' => json_encode($before), 'after' => json_encode($inbox->present($survivor)), 'moved_counts' => json_encode($counts), 'created_at' => now()]);
            $review->update(['status' => 'merged', 'survivor_client_id' => $survivor->id, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            ClientDuplicateReview::where('id', '!=', $review->id)->where('status', 'pending')->where(fn ($query) => $query->where('client_a_id', $source->id)->orWhere('client_b_id', $source->id))->delete();
            $source->delete();
            return $survivor->id;
        }, 3);
        $inbox->scan();
        return response()->json(['message' => 'Clients fusionnes avec succes.', 'client_id' => $survivorId]);
    }

    public function undoPreview(ClientDuplicateReview $duplicate, ClientMergeUndo $undo)
    {
        return response()->json($undo->preview($duplicate));
    }

    public function undo(Request $request, ClientDuplicateReview $duplicate, ClientMergeUndo $undo)
    {
        $data = $request->validate(['audit_id' => 'required|integer', 'confirmation' => 'required|in:annuler']);
        $undo->undo($duplicate, $data['audit_id'], $request->user()->id);
        return response()->json(['message' => 'Fusion annulee. Les deux clients sont restaures.']);
    }

    private function counts(int $id): array
    {
        $counts = [];
        foreach (self::TABLES as $table) if (Schema::hasTable($table)) $counts[$table] = DB::table($table)->where('client_id', $id)->count();
        return $counts;
    }

    private function version($clients): string
    {
        return hash('sha256', $clients->map(fn ($client) => [$client->getAttributes(), $this->counts($client->id)])->toJson());
    }
}
