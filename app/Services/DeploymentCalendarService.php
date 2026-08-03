<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Task;
use App\Models\User;
use App\Services\Media\MediaUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DeploymentCalendarService
{
    public function baseQuery(): Builder
    {
        return Deployment::with([
            'responsible.image',
            'driver.image',
            'city',
        ])
            ->withCount('tasks')
            ->with(['tasks' => function ($query) {
                $query->select(
                    'id',
                    'deployment_id',
                    'status',
                    'technician_id',
                    'client_id',
                    'task_type',
                    'is_paid'
                );
            }]);
    }

    public function userCanViewAll(): bool
    {
        $user = auth()->user();

        return $user
            && $user->profile
            && $user->profile->permissions->pluck('code')->contains('tasks_view_all');
    }

    public function filterDeploymentsForUser(Collection $deployments, ?int $userId = null): Collection
    {
        if ($this->userCanViewAll()) {
            return $deployments;
        }

        $userId = (int) ($userId ?? auth()->id());

        return $deployments->filter(function (Deployment $deployment) use ($userId) {
            $teamIds = array_map('intval', (array) ($deployment->team_member_ids ?? []));
            $hosterIds = array_map('intval', (array) ($deployment->hosters ?? []));

            return (int) $deployment->responsible_id === $userId
                || (int) $deployment->driver_id === $userId
                || in_array($userId, $teamIds, true)
                || in_array($userId, $hosterIds, true)
                || $deployment->tasks->contains('technician_id', $userId);
        })->values();
    }

    public function userCanAccessDeployment(Deployment $deployment, ?int $userId = null): bool
    {
        if ($this->userCanViewAll()) {
            return true;
        }

        $userId = (int) ($userId ?? auth()->id());

        return $this->filterDeploymentsForUser(collect([$deployment]), $userId)->isNotEmpty();
    }

    /**
     * @return Collection<int, User>
     */
    public function indexUsersForDeployments(Collection $deployments): Collection
    {
        $ids = [];

        foreach ($deployments as $deployment) {
            if (!empty($deployment->team_member_ids)) {
                $ids = array_merge($ids, (array) $deployment->team_member_ids);
            }
            if (!empty($deployment->hosters)) {
                $ids = array_merge($ids, (array) $deployment->hosters);
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return collect();
        }

        return User::with('image')->whereIn('id', $ids)->get()->keyBy('id');
    }

    public function groupAndMapSummaries(Collection $deployments): array
    {
        $usersById = $this->indexUsersForDeployments($deployments);

        $grouped = $deployments->groupBy(function (Deployment $deployment) {
            return Carbon::parse($deployment->deployment_date)->startOfDay()->format('Y-m-d');
        });

        $result = [];

        foreach ($grouped as $date => $deploymentsForDate) {
            $result[$date] = $deploymentsForDate
                ->map(fn (Deployment $deployment) => $this->mapDeploymentSummary($deployment, $usersById))
                ->values();
        }

        return $result;
    }

    public function mapDeploymentSummary(Deployment $deployment, Collection $usersById): array
    {
        $teamMemberIds = array_map('intval', (array) ($deployment->team_member_ids ?? []));
        $hosterIds = array_map('intval', (array) ($deployment->hosters ?? []));

        $teamMembers = collect($teamMemberIds)
            ->map(fn (int $id) => $usersById->get($id))
            ->filter()
            ->values();

        $hosters = collect($hosterIds)
            ->map(fn (int $id) => $usersById->get($id))
            ->filter()
            ->values();

        return [
            'id' => $deployment->id,
            'title' => $deployment->title,
            'deployment_date' => $deployment->deployment_date ? $deployment->deployment_date->format('Y-m-d') : null,
            'description' => $deployment->description,
            'city_id' => $deployment->city_id,
            'city_name' => $deployment->city ? $deployment->city->name : null,
            'responsible_id' => $deployment->responsible_id,
            'responsible_name' => $deployment->responsible
                ? ($deployment->responsible->first_name . ' ' . $deployment->responsible->last_name)
                : null,
            'responsible_image' => $deployment->responsible && $deployment->responsible->image
                ? MediaUrl::image($deployment->responsible->image->image_name)
                : null,
            'driver_id' => $deployment->driver_id,
            'driver_name' => $deployment->driver
                ? ($deployment->driver->first_name . ' ' . $deployment->driver->last_name)
                : null,
            'driver_image' => $deployment->driver && $deployment->driver->image
                ? MediaUrl::image($deployment->driver->image->image_name)
                : null,
            'team_member_ids' => $deployment->team_member_ids,
            'team_members' => $teamMembers->map(fn (User $member) => $this->mapUserBrief($member)),
            'hosters' => $deployment->hosters ?? [],
            'hosters_detail' => $hosters->map(fn (User $member) => $this->mapUserBrief($member))->values()->all(),
            'tasks' => $deployment->tasks->map(fn (Task $task) => $this->mapTaskStub($task))->values()->all(),
            'tasks_count' => (int) ($deployment->tasks_count ?? $deployment->tasks->count()),
        ];
    }

    public function mapTaskStub(Task $task): array
    {
        return [
            'id' => $task->id,
            'status' => $task->status,
            'technician_id' => $task->technician_id,
            'client_id' => $task->client_id,
            'task_type' => $task->task_type,
            'is_paid' => (bool) $task->is_paid,
        ];
    }

    public function mapFullTask(Task $task, ?int $viewerUserId = null): array
    {
        $viewerUserId = $viewerUserId ?? auth()->id();

        return [
            'id' => $task->id,
            'task_name' => $task->task_name,
            'task_type' => $task->task_type,
            'description' => $task->description,
            'status' => $task->status,
            'urgent' => $task->urgent,
            'is_paid' => $task->is_paid,
            'hourly_rate' => $task->hourly_rate,
            'amount_paid' => $task->amount_paid,
            'admin_delivery_amount' => $task->admin_delivery_amount,
            'admin_delivery_task_id' => $task->admin_delivery_task_id,
            'task_date' => $task->task_date ? $task->task_date->format('Y-m-d') : null,
            'has_ongoing_visit' => (bool) ($task->has_ongoing_visit ?: false),
            'deployment_id' => $task->deployment_id,
            'technician_id' => $task->technician_id,
            'technician_name' => $task->technician
                ? ($task->technician->first_name . ' ' . $task->technician->last_name)
                : null,
            'technician_image' => $task->technician && $task->technician->image
                ? MediaUrl::image($task->technician->image->image_name)
                : null,
            'client_id' => $task->client_id,
            'client_name' => $task->client
                ? ($task->client->first_name . ' ' . $task->client->last_name)
                : null,
            'client_image' => $task->client && $task->client->image
                ? MediaUrl::image($task->client->image->image_name)
                : null,
            'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
            'helping_user_ids' => $task->helping_user_ids,
            'is_main_technician' => $task->technician_id == $viewerUserId,
        ];
    }

    private function mapUserBrief(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->first_name . ' ' . $user->last_name,
            'image' => $user->image ? MediaUrl::image($user->image->image_name) : null,
        ];
    }
}
