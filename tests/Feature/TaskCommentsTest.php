<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskCommentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('profiles', function (Blueprint $table) { $table->id(); $table->string('profile_name'); $table->timestamps(); });
        Schema::create('permissions', function (Blueprint $table) { $table->id(); $table->string('code'); $table->string('label'); $table->timestamps(); });
        Schema::create('profile_permission', function (Blueprint $table) { $table->unsignedBigInteger('profile_id'); $table->unsignedBigInteger('permission_id'); });
        Schema::create('images', function (Blueprint $table) { $table->id(); $table->string('image_name'); });
        Schema::create('users', function (Blueprint $table) { $table->id(); $table->string('first_name'); $table->string('last_name'); $table->unsignedBigInteger('profile_id'); $table->unsignedBigInteger('image_id')->nullable(); $table->timestamps(); });
        Schema::create('tasks', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('technician_id'); $table->unsignedBigInteger('create_by')->nullable(); $table->json('helping_user_ids')->nullable(); $table->timestamps(); });
        (require database_path('migrations/2026_10_30_190000_create_task_comments_table.php'))->up();
    }

    public function test_main_technician_can_post_and_author_cannot_be_spoofed(): void
    {
        $user = $this->user(); $other = $this->user(); $task = $this->task($user);
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => '  Intervention terminee.  ', 'user_id' => $other->id, 'task_id' => 999])
            ->assertCreated()->assertJsonPath('comment.body', 'Intervention terminee.')->assertJsonPath('comment.author.id', $user->id);
        $this->assertSame($user->id, TaskComment::first()->user_id);
        $this->assertSame($task->id, TaskComment::first()->task_id);
    }

    public function test_helping_user_can_post_even_when_ids_are_strings(): void
    {
        $main = $this->user(); $helper = $this->user(); $task = $this->task($main, [(string) $helper->id]);
        Sanctum::actingAs($helper);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertOk()->assertJsonPath('can_post', true);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Note assistant.'])->assertCreated();
    }

    public function test_unassigned_manager_requires_both_permissions(): void
    {
        $task = $this->task($this->user());
        $manager = $this->user(['tasks_read', 'tasks_view_all', 'tasks_write']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Note responsable.'])->assertCreated();
        $viewer = $this->user(['tasks_read', 'tasks_view_all']);
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertOk()->assertJsonPath('can_post', false);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Interdit.'])->assertForbidden();
        Sanctum::actingAs($this->user(['tasks_read', 'tasks_write']));
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Interdit.'])->assertForbidden();
        $this->assertSame(1, TaskComment::count());
    }

    public function test_unrelated_user_and_unassigned_creator_cannot_read_or_post(): void
    {
        $user = $this->user(); $task = $this->task($this->user(), [], $user);
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertForbidden();
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Interdit.'])->assertForbidden();
    }

    public function test_removed_helper_cannot_post_using_previous_access(): void
    {
        $helper = $this->user(); $task = $this->task($this->user(), [$helper->id]);
        Sanctum::actingAs($helper);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertOk()->assertJsonPath('can_post', true);
        $task->update(['helping_user_ids' => []]);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Interdit.'])->assertForbidden();
    }

    public function test_empty_and_oversized_comments_are_rejected(): void
    {
        $user = $this->user(); $task = $this->task($user); Sanctum::actingAs($user);
        foreach (['   ', str_repeat('a', 5001)] as $body) $this->postJson("/api/v1/tasks/{$task->id}/comments", compact('body'))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, TaskComment::count());
    }

    public function test_cursor_pagination_keeps_new_posts_out_of_older_pages(): void
    {
        $user = $this->user(); $task = $this->task($user); Sanctum::actingAs($user);
        for ($i = 1; $i <= 25; $i++) TaskComment::create(['task_id' => $task->id, 'user_id' => $user->id, 'body' => "Comment $i"]);
        $first = $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertOk()->assertJsonCount(20, 'comments')->assertJsonPath('next_cursor', 6);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Nouveau.'])->assertCreated();
        $second = $this->getJson("/api/v1/tasks/{$task->id}/comments?before_id=6")->assertOk()->assertJsonCount(5, 'comments')->assertJsonPath('next_cursor', null);
        $this->assertSame([], array_intersect(array_column($first->json('comments'), 'id'), array_column($second->json('comments'), 'id')));
    }

    public function test_comments_survive_author_deletion_and_follow_task_deletion(): void
    {
        $author = $this->user(); $task = $this->task($author);
        TaskComment::create(['task_id' => $task->id, 'user_id' => $author->id, 'body' => 'Historique.']);
        $author->delete();
        Sanctum::actingAs($this->user(['tasks_read', 'tasks_view_all']));
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertOk()->assertJsonPath('comments.0.author', null)->assertJsonPath('comments.0.body', 'Historique.');
        $task->delete(); $this->assertSame(0, TaskComment::count());
    }

    public function test_authentication_and_task_read_permission_are_required(): void
    {
        $user = $this->user([]); $task = $this->task($user);
        $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertUnauthorized();
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Interdit.'])->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Interdit.'])->assertForbidden();
    }

    private function user(array $codes = ['tasks_read']): User
    {
        $profile = Profile::create(['profile_name' => 'Test']);
        foreach ($codes as $code) $profile->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['label' => $code])->id);
        return User::create(['first_name' => 'Test', 'last_name' => 'Utilisateur', 'profile_id' => $profile->id]);
    }

    private function task(User $main, array $helpers = [], ?User $creator = null): Task
    {
        $id = DB::table('tasks')->insertGetId(['technician_id' => $main->id, 'helping_user_ids' => json_encode($helpers), 'create_by' => $creator?->id]);
        return Task::findOrFail($id);
    }
}
