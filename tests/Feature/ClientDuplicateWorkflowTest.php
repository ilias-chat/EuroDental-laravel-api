<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Crm\ClientDuplicateController;
use App\Models\Client;
use App\Models\ClientDuplicateReview;
use App\Services\Clients\ClientDuplicateInbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientDuplicateWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            foreach (['first_name', 'last_name', 'email', 'phone_number', 'fixed_phone_number', 'address', 'ice', 'description', 'password', 'source'] as $field) $table->string($field)->nullable();
            $table->unsignedBigInteger('city_id')->nullable(); $table->unsignedBigInteger('image_id')->nullable();
            $table->boolean('validated')->default(true); $table->boolean('handled')->default(true); $table->timestamps();
        });
        Schema::create('webshop_data', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('client_id'); $table->timestamps(); });
        Schema::create('personal_access_tokens', function (Blueprint $table) { $table->id(); $table->string('tokenable_type'); $table->unsignedBigInteger('tokenable_id'); });
        foreach (['orders', 'tasks', 'invoices', 'purchases', 'proposed_tasks'] as $name) Schema::create($name, function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('client_id'); });
        (require database_path('migrations/2026_10_29_210000_create_client_duplicate_reviews.php'))->up();
        (require database_path('migrations/2026_10_29_230000_add_undo_to_client_merge_audits.php'))->up();
    }

    public function test_ignored_pairs_remain_ignored_after_scanning(): void
    {
        $this->pair();
        $inbox = app(ClientDuplicateInbox::class); $inbox->scan();
        $review = ClientDuplicateReview::firstOrFail();
        app(ClientDuplicateController::class)->review($this->request(['status' => 'ignored']), $review);
        $inbox->scan();
        $this->assertSame(1, ClientDuplicateReview::count());
        $this->assertSame('ignored', $review->fresh()->status);
    }

    public function test_merge_moves_every_link_and_records_an_audit(): void
    {
        [$keep, $source] = $this->pair();
        foreach (['orders', 'tasks', 'invoices', 'purchases', 'proposed_tasks'] as $table) DB::table($table)->insert(['client_id' => $source->id]);
        $inbox = app(ClientDuplicateInbox::class); $inbox->scan(); $review = ClientDuplicateReview::firstOrFail();
        $controller = app(ClientDuplicateController::class);
        $preview = $controller->preview($review, $inbox)->getData(true);
        $controller->merge($this->request(['survivor_id' => $keep->id, 'field_sources' => ['phone_number' => $source->id], 'version' => $preview['version'], 'confirmation' => 'fusionner']), $review, $inbox);
        $this->assertNull(Client::find($source->id));
        foreach (['orders', 'tasks', 'invoices', 'purchases', 'proposed_tasks'] as $table) $this->assertSame($keep->id, DB::table($table)->value('client_id'));
        $this->assertSame('0612345678', $keep->fresh()->phone_number);
        $this->assertSame('merged', $review->fresh()->status);
        $this->assertSame(1, DB::table('client_merge_audits')->count());
    }

    public function test_merge_preserves_a_source_webshop_account_by_rejecting_wrong_survivor(): void
    {
        [$keep, $source] = $this->pair(); $source->update(['password' => 'secret-hash']);
        $inbox = app(ClientDuplicateInbox::class); $inbox->scan(); $review = ClientDuplicateReview::firstOrFail();
        $controller = app(ClientDuplicateController::class); $preview = $controller->preview($review, $inbox)->getData(true);
        try {
            $controller->merge($this->request(['survivor_id' => $keep->id, 'field_sources' => [], 'version' => $preview['version'], 'confirmation' => 'fusionner']), $review, $inbox);
            $this->fail('Expected account protection.');
        } catch (ValidationException) {
            $this->assertSame(2, Client::count());
            $this->assertSame(0, DB::table('client_merge_audits')->count());
        }
    }

    public function test_undo_restores_original_ownership_and_leaves_new_records_with_survivor(): void
    {
        [$keep, $source] = $this->pair();
        $tables = ['orders', 'tasks', 'invoices', 'purchases', 'proposed_tasks', 'webshop_data'];
        foreach ($tables as $table) DB::table($table)->insert(['client_id' => $source->id]);
        DB::table('orders')->insert(['client_id' => $keep->id]);
        DB::table('personal_access_tokens')->insert(['tokenable_id' => $source->id, 'tokenable_type' => Client::class]);
        [$review, $inbox] = $this->mergePair($keep);
        $newOrder = DB::table('orders')->insertGetId(['client_id' => $keep->id]);
        $undo = app(\App\Services\Clients\ClientMergeUndo::class);
        $preview = $undo->preview($review);
        $this->assertSame(1, $preview['counts']['orders']);
        $undo->undo($review, $preview['audit_id'], 1);
        foreach ($tables as $table) $this->assertSame($source->id, DB::table($table)->where('id', 1)->value('client_id'));
        $this->assertSame($keep->id, DB::table('orders')->where('id', 2)->value('client_id'));
        $this->assertSame($keep->id, DB::table('orders')->where('id', $newOrder)->value('client_id'));
        $this->assertNull($keep->fresh()->phone_number);
        $this->assertSame('0612345678', Client::findOrFail($source->id)->phone_number);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNotNull(DB::table('client_merge_audits')->value('undone_at'));
        $this->assertSame('pending', $review->fresh()->status);
    }

    public function test_undo_rejects_edited_transferred_records_without_partial_restoration(): void
    {
        [$keep, $source] = $this->pair();
        Schema::table('tasks', fn (Blueprint $table) => $table->string('description')->nullable());
        DB::table('tasks')->insert(['client_id' => $source->id, 'description' => 'Before']);
        [$review] = $this->mergePair($keep);
        $undo = app(\App\Services\Clients\ClientMergeUndo::class); $preview = $undo->preview($review);
        DB::table('tasks')->update(['description' => 'Edited after merge']);
        $this->assertUndoConflict(fn () => $undo->undo($review, $preview['audit_id'], 1));
        $this->assertNull(Client::find($source->id));
        $this->assertSame($keep->id, DB::table('tasks')->value('client_id'));
        $this->assertNull(DB::table('client_merge_audits')->value('undone_at'));
    }

    public function test_undo_rejects_changed_survivor_and_legacy_history(): void
    {
        [$keep] = $this->pair(); [$review] = $this->mergePair($keep);
        $undo = app(\App\Services\Clients\ClientMergeUndo::class);
        $keep->fresh()->update(['address' => 'New address']);
        $this->assertUndoConflict(fn () => $undo->preview($review));
        DB::table('client_merge_audits')->update(['undo_data' => null]);
        $this->assertUndoConflict(fn () => $undo->preview($review));
    }

    public function test_undo_cannot_run_twice(): void
    {
        [$keep] = $this->pair(); [$review] = $this->mergePair($keep);
        $undo = app(\App\Services\Clients\ClientMergeUndo::class); $preview = $undo->preview($review);
        $undo->undo($review, $preview['audit_id'], 1);
        $this->assertUndoConflict(fn () => $undo->undo($review, $preview['audit_id'], 1));
        $this->assertSame(2, Client::count());
    }

    public function test_undo_rejects_deleted_and_reassigned_records(): void
    {
        [$keep, $source] = $this->pair();
        DB::table('orders')->insert(['client_id' => $source->id]);
        [$review] = $this->mergePair($keep);
        $undo = app(\App\Services\Clients\ClientMergeUndo::class);
        DB::table('orders')->update(['client_id' => 999]);
        $this->assertUndoConflict(fn () => $undo->preview($review));
        DB::table('orders')->delete();
        $this->assertUndoConflict(fn () => $undo->preview($review));
        $this->assertNull(Client::find($source->id));
    }

    private function mergePair(Client $keep): array
    {
        $inbox = app(ClientDuplicateInbox::class); $inbox->scan();
        $review = ClientDuplicateReview::where('status', 'pending')->firstOrFail();
        $controller = app(ClientDuplicateController::class); $preview = $controller->preview($review, $inbox)->getData(true);
        $controller->merge($this->request(['survivor_id' => $keep->id, 'field_sources' => ['phone_number' => $review->client_b_id], 'version' => $preview['version'], 'confirmation' => 'fusionner']), $review, $inbox);
        return [$review->fresh(), $inbox];
    }

    private function assertUndoConflict(callable $action): void
    {
        try { $action(); $this->fail('Expected restoration conflict.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
    }

    private function pair(): array
    {
        return [Client::create(['first_name' => 'Mohamed', 'last_name' => 'Rami', 'source' => 'admin']), Client::create(['first_name' => 'Mohammd', 'last_name' => 'Rami', 'phone_number' => '0612345678', 'source' => 'admin'])];
    }

    public function test_creation_requires_an_explicit_override_for_matching_client(): void
    {
        $this->pair();
        $response = app(\App\Http\Controllers\Api\V1\Crm\ClientController::class)->store($this->request(['first_name' => 'MOHAMED', 'last_name' => 'Rami']), app(ClientDuplicateInbox::class));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertNotEmpty($response->getData(true)['matches']);
        $this->assertSame(2, Client::count());
    }

    public function test_changed_records_require_a_fresh_merge_preview(): void
    {
        [$keep, $source] = $this->pair();
        $inbox = app(ClientDuplicateInbox::class); $inbox->scan(); $review = ClientDuplicateReview::firstOrFail();
        $controller = app(ClientDuplicateController::class); $preview = $controller->preview($review, $inbox)->getData(true);
        $source->update(['last_name' => 'Different']);
        try {
            $controller->merge($this->request(['survivor_id' => $keep->id, 'field_sources' => [], 'version' => $preview['version'], 'confirmation' => 'fusionner']), $review, $inbox);
            $this->fail('Expected stale preview rejection.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame(2, Client::count());
        }
    }

    public function test_explicit_creation_override_saves_the_client_and_queues_the_pair(): void
    {
        Client::create(['first_name' => 'Mohamed', 'last_name' => 'Rami', 'source' => 'admin']);
        $response = app(\App\Http\Controllers\Api\V1\Crm\ClientController::class)->store($this->request(['first_name' => 'Mohammd', 'last_name' => 'Rami', 'allow_duplicate' => '1']), app(ClientDuplicateInbox::class));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, Client::count());
        $this->assertSame('pending', ClientDuplicateReview::firstOrFail()->status);
    }

    private function request(array $data): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => (object) ['id' => 1]);
        return $request;
    }
}
