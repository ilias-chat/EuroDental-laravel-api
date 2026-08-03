<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaUrl;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentExpense;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TrackingController extends Controller
{
    public function suiviMonthTasks(Request $request)
    {
        $start = \Carbon\Carbon::parse($request->query('start'));
        $end = \Carbon\Carbon::parse($request->query('end'));
        $userIdParam = $request->query('user_id');
        $isAllUsers = ($userIdParam === 'all');
        $userId = $isAllUsers ? null : $userIdParam;
        
        if (!$userId && !$isAllUsers) {
            return response()->json([
                'deployments' => [],
                'tasks' => []
            ]);
        }

        \Log::info('SuiviMonthTasks called', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'user_id' => $userIdParam,
            'is_all_users' => $isAllUsers
        ]);

        // Fetch deployments for the month
        $deploymentsQuery = Deployment::with([
            'responsible.image', 
            'driver.image', 
            'city',
            'tasks.technician.image',
            'tasks.client.image',
            'tasks.client.city',
            'tasks.services'
        ])->whereBetween('deployment_date', [$start, $end]);
        
        $deployments = $deploymentsQuery->get();

        // Fetch independent tasks (not in any deployment) filtered by user
        $tasksQuery = Task::with(['technician.image', 'client.image', 'client.city', 'services'])
            ->whereBetween('task_date', [$start, $end])
            ->whereNull('deployment_id');
        
        if (!$isAllUsers) {
            $tasksQuery->where(function($q) use ($userId) {
                $q->where('technician_id', $userId)
                  ->orWhereJsonContains('helping_user_ids', (int)$userId);
            });
        }
        
        $tasks = $tasksQuery->get();

        // Filter deployments by user involvement (or include all if all users)
        if (!$isAllUsers) {
            $deployments = $deployments->filter(function($deployment) use ($userId) {
                $uid = (int) $userId;
                $teamIds = array_map('intval', (array) ($deployment->team_member_ids ?? []));
                $hosterIds = array_map('intval', (array) ($deployment->hosters ?? []));
                // Check if user is in deployment team (responsible, driver, team member, or hoster)
                if ((int) $deployment->responsible_id === $uid
                    || (int) $deployment->driver_id === $uid
                    || in_array($uid, $teamIds, true)
                    || in_array($uid, $hosterIds, true)) {
                    return true;
                }
                // Check if user is main tech or helping user in any task within this deployment
                return $deployment->tasks->contains(function($task) use ($userId) {
                    return $task->technician_id == $userId 
                        || (is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids));
                });
            });
        }

        \Log::info('Suivi Tasks and Deployments found', [
            'tasks_count' => $tasks->count(),
            'deployments_count' => $deployments->count(),
            'date_range' => $start->toDateString() . ' to ' . $end->toDateString(),
            'user_id' => $userId
        ]);

        // Group deployments by date
        $groupedDeployments = $deployments->groupBy(function($deployment) {
            return \Carbon\Carbon::parse($deployment->deployment_date)->startOfDay()->format('Y-m-d');
        });

        // Group independent tasks by date
        $groupedTasks = $tasks->groupBy(function($task) {
            return \Carbon\Carbon::parse($task->task_date)->startOfDay()->format('Y-m-d');
        });

        // Build result with both deployments and tasks
        $result = [
            'deployments' => [],
            'tasks' => []
        ];

        // Map deployments
        foreach ($groupedDeployments as $date => $deploymentsForDate) {
            $result['deployments'][$date] = $deploymentsForDate->map(function($deployment) use ($userId, $isAllUsers) {
                $teamMembers = $deployment->teamMembers();
                $hosters = $deployment->getHosters();
                return [
                    'id' => $deployment->id,
                    'title' => $deployment->title,
                    'deployment_date' => $deployment->deployment_date ? $deployment->deployment_date->format('Y-m-d') : null,
                    'description' => $deployment->description,
                    'city_id' => $deployment->city_id,
                    'city_name' => $deployment->city ? $deployment->city->name : null,
                    'responsible_id' => $deployment->responsible_id,
                    'responsible_name' => $deployment->responsible ? ($deployment->responsible->first_name . ' ' . $deployment->responsible->last_name) : null,
                    'responsible_image' => $deployment->responsible && $deployment->responsible->image ? MediaUrl::image($deployment->responsible->image->image_name) : null,
                    'driver_id' => $deployment->driver_id,
                    'driver_name' => $deployment->driver ? ($deployment->driver->first_name . ' ' . $deployment->driver->last_name) : null,
                    'driver_image' => $deployment->driver && $deployment->driver->image ? MediaUrl::image($deployment->driver->image->image_name) : null,
                    'team_member_ids' => $deployment->team_member_ids,
                    'team_members' => $teamMembers->map(function($member) {
                        return [
                            'id' => $member->id,
                            'name' => $member->first_name . ' ' . $member->last_name,
                            'image' => $member->image ? MediaUrl::image($member->image->image_name) : null,
                        ];
                    }),
                    'hosters' => $deployment->hosters ?? [],
                    'hosters_detail' => $hosters->map(function($member) {
                        return [
                            'id' => $member->id,
                            'name' => $member->first_name . ' ' . $member->last_name,
                            'image' => $member->image ? MediaUrl::image($member->image->image_name) : null,
                        ];
                    })->values()->all(),
                    'tasks' => $deployment->tasks->map(function($task) use ($userId, $isAllUsers) {
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
                            'technician_name' => $task->technician ? ($task->technician->first_name . ' ' . $task->technician->last_name) : null,
                            'technician_image' => $task->technician && $task->technician->image ? MediaUrl::image($task->technician->image->image_name) : null,
                            'client_id' => $task->client_id,
                            'client_name' => $task->client ? ($task->client->first_name . ' ' . $task->client->last_name) : null,
                            'client_image' => $task->client && $task->client->image ? MediaUrl::image($task->client->image->image_name) : null,
                            'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                            'helping_user_ids' => $task->helping_user_ids,
                            'is_main_technician' => $isAllUsers ? false : ($task->technician_id == $userId),
                        ];
                    }),
                    'tasks_count' => $deployment->tasks->count(),
                ];
            });
        }

        // Map independent tasks
        foreach ($groupedTasks as $date => $tasksForDate) {
            $result['tasks'][$date] = $tasksForDate->map(function($task) use ($userId) {
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
                    'technician_id' => $task->technician_id,
                    'technician_name' => $task->technician ? ($task->technician->first_name . ' ' . $task->technician->last_name) : null,
                    'technician_image' => $task->technician && $task->technician->image ? MediaUrl::image($task->technician->image->image_name) : null,
                    'client_id' => $task->client_id,
                    'client_name' => $task->client ? ($task->client->first_name . ' ' . $task->client->last_name) : null,
                    'client_image' => $task->client && $task->client->image ? MediaUrl::image($task->client->image->image_name) : null,
                    'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                    'helping_user_ids' => $task->helping_user_ids,
                    'is_main_technician' => $task->technician_id == $userId,
                ];
            });
        }

        \Log::info('Returning suivi month result', ['result' => $result]);
        
        return response()->json($result);
    }

    public function suiviStats(Request $request)
    {
        $userIdParam = $request->query('user_id');
        $isAllUsers = ($userIdParam === 'all');
        $userId = $isAllUsers ? null : (int)$userIdParam;
        $year = $request->query('year'); // optional
        $month = $request->query('month'); // optional (1-12)
        $taskType = $request->query('task_type'); // optional - for filtering monthly chart by task type
        
        if (!$userId && !$isAllUsers) {
            return response()->json(['error' => 'User ID required'], 400);
        }

        // Keep statistics available on cloud instances awaiting the hosters migration.
        $hasHostersColumn = Schema::hasColumn('deployments', 'hosters');
        
        // Build date filters
        $startDate = null;
        $endDate = null;
        if ($year) {
            $startDate = Carbon::create($year, $month ?? 1, 1)->startOfDay();
            $endDate = $month 
                ? Carbon::create($year, $month, 1)->endOfMonth()->endOfDay()
                : Carbon::create($year, 12, 31)->endOfDay();
        }
        
        // Helper function to apply date filter
        $applyDateFilter = function($query, $dateColumn = 'created_at') use ($startDate, $endDate) {
            if ($startDate && $endDate) {
                return $query->whereBetween($dateColumn, [$startDate, $endDate]);
            }
            return $query;
        };
        
        // Helper function to apply user filter (or skip if all users)
        $applyUserFilter = function($query, $column = 'created_by') use ($userId, $isAllUsers) {
            if (!$isAllUsers && $userId) {
                return $query->where($column, $userId);
            }
            return $query;
        };
        
        // ========== ORDERS STATISTICS ==========
        $ordersQuery = $applyUserFilter(Order::query());
        $ordersAllTime = clone $ordersQuery;
        
        $totalOrders = $ordersAllTime->count();
        $totalRevenue = $ordersAllTime->sum('total_with_tax') ?? 0;
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        
        $ordersFiltered = clone $ordersQuery;
        $applyDateFilter($ordersFiltered);
        $ordersThisPeriod = $ordersFiltered->count();
        $revenueThisPeriod = $applyDateFilter(clone $ordersQuery)->sum('total_with_tax') ?? 0;
        
        // Orders by status
        $ordersByStatus = $applyDateFilter(clone $ordersQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        // Current year and month for comparison
        $currentYear = Carbon::now()->year;
        $currentMonth = Carbon::now()->month;
        $yearStart = Carbon::create($currentYear, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($currentYear, 12, 31)->endOfDay();
        $monthStart = Carbon::create($currentYear, $currentMonth, 1)->startOfDay();
        $monthEnd = Carbon::create($currentYear, $currentMonth, 1)->endOfMonth()->endOfDay();
        
        $ordersThisYear = $applyUserFilter(Order::query())
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->count();
        $revenueThisYear = $applyUserFilter(Order::query())
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->sum('total_with_tax') ?? 0;
        
        $ordersThisMonth = $applyUserFilter(Order::query())
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();
        $revenueThisMonth = $applyUserFilter(Order::query())
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('total_with_tax') ?? 0;
        
        // Monthly orders breakdown for selected year (if year is provided)
        // Note: This section is used for orderRanks calculation, but $monthlyOrders gets overwritten later
        $monthlyOrders = [];
        if ($year) {
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                
                // Orders in this month
                $ordersInMonthQuery = Order::whereBetween('created_at', [$monthStart, $monthEnd]);
                if (!$isAllUsers && $userId) {
                    $ordersInMonthQuery->where('created_by', $userId);
                }
                $ordersInMonth = $ordersInMonthQuery->count();
                
                // Revenue from orders in this month
                $revenueInMonthQuery = Order::whereBetween('created_at', [$monthStart, $monthEnd]);
                if (!$isAllUsers && $userId) {
                    $revenueInMonthQuery->where('created_by', $userId);
                }
                $revenueInMonth = $revenueInMonthQuery->sum('total_with_tax') ?? 0;
                
                $monthlyOrders[] = [
                    'month' => $month,
                    'month_name' => Carbon::create($year, $month, 1)->locale('fr_FR')->monthName,
                    'orders' => $ordersInMonth,
                    'revenue' => round($revenueInMonth, 2),
                ];
            }
            
            // Calculate year totals and averages for orders
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
            $daysInYear = $yearStart->diffInDays($yearEnd) + 1;
            
            $totalOrdersYearQuery = Order::whereBetween('created_at', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $totalOrdersYearQuery->where('created_by', $userId);
            }
            $totalOrdersYear = $totalOrdersYearQuery->count();
            
            $totalRevenueOrdersYearQuery = Order::whereBetween('created_at', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $totalRevenueOrdersYearQuery->where('created_by', $userId);
            }
            $totalRevenueOrdersYear = $totalRevenueOrdersYearQuery->sum('total_with_tax') ?? 0;
            
            $ordersPerMonth = round($totalOrdersYear / 12, 2);
            $avgRevenueOrdersPerMonth = round($totalRevenueOrdersYear / 12, 2);
            
            // Calculate rankings for orders for all users for the year
            $allUsers = User::where('is_blocked', false)->get();
            $orderUserMetrics = [];
            
            foreach ($allUsers as $user) {
                $uid = $user->id;
                
                // Get orders for this user in the year
                $userTotalOrdersYear = Order::where('created_by', $uid)
                    ->whereBetween('created_at', [$yearStart, $yearEnd])
                    ->count();
                
                $userTotalRevenueOrdersYear = Order::where('created_by', $uid)
                    ->whereBetween('created_at', [$yearStart, $yearEnd])
                    ->sum('total_with_tax') ?? 0;
                
                $userOrdersPerMonth = round($userTotalOrdersYear / 12, 2);
                $userAvgRevenueOrdersPerMonth = round($userTotalRevenueOrdersYear / 12, 2);
                
                $orderUserMetrics[] = [
                    'user_id' => $uid,
                    'total_orders' => $userTotalOrdersYear,
                    'orders_per_month' => $userOrdersPerMonth,
                    'total_revenue' => round($userTotalRevenueOrdersYear, 2),
                    'avg_revenue_per_month' => $userAvgRevenueOrdersPerMonth,
                ];
            }
            
            // Calculate ranks for orders
            $orderRanks = [
                'total_orders' => null,
                'orders_per_month' => null,
                'total_revenue' => null,
                'avg_revenue_per_month' => null,
            ];
            
            if (!empty($orderUserMetrics)) {
                // Helper function to calculate rank (same as for tasks)
                $calculateRank = function($metricsArray, $targetUserId, $field, $ascending = false, $zeroLast = false) {
                    // Create a fresh copy of the array for this calculation
                    $sortedMetrics = [];
                    foreach ($metricsArray as $item) {
                        $value = (float)($item[$field] ?? 0);
                        $sortedMetrics[] = [
                            'user_id' => $item['user_id'],
                            'value' => $value
                        ];
                    }
                    
                    // Sort by value
                    usort($sortedMetrics, function($a, $b) use ($ascending, $zeroLast) {
                        $valA = $a['value'];
                        $valB = $b['value'];
                        
                        // Handle zero values for special cases
                        if ($zeroLast) {
                            if ($valA == 0 && $valB != 0) return 1; // A goes last
                            if ($valA != 0 && $valB == 0) return -1; // B goes last
                            if ($valA == 0 && $valB == 0) return 0; // Keep order
                        }
                        
                        if ($ascending) {
                            return $valA <=> $valB;
                        }
                        return $valB <=> $valA;
                    });
                    
                    // Calculate ranks with proper tie handling
                    $rank = 1;
                    $targetRank = null;
                    
                    for ($i = 0; $i < count($sortedMetrics); $i++) {
                        // If this is not the first item and value changed, update rank
                        if ($i > 0) {
                            $prevValue = $sortedMetrics[$i - 1]['value'];
                            $currValue = $sortedMetrics[$i]['value'];
                            
                            // Check if value changed (with tolerance for floating point)
                            if (abs($currValue - $prevValue) > 0.0001) {
                                $rank = $i + 1;
                            }
                        }
                        
                        // Check if this is the target user (ensure both are int for comparison)
                        $currentUserId = (int)$sortedMetrics[$i]['user_id'];
                        $targetUserIdInt = (int)$targetUserId;
                        
                        if ($currentUserId === $targetUserIdInt) {
                            $targetRank = $rank;
                            break;
                        }
                    }
                    
                    // If user not found, return null (shouldn't happen, but safety check)
                    return $targetRank;
                };
                
                // Calculate ranks for each order metric (skip if all users)
                if (!$isAllUsers) {
                    $orderRanks['total_orders'] = $calculateRank($orderUserMetrics, $userId, 'total_orders', false);
                    $orderRanks['orders_per_month'] = $calculateRank($orderUserMetrics, $userId, 'orders_per_month', false);
                    $orderRanks['total_revenue'] = $calculateRank($orderUserMetrics, $userId, 'total_revenue', false);
                    $orderRanks['avg_revenue_per_month'] = $calculateRank($orderUserMetrics, $userId, 'avg_revenue_per_month', false);
                }
            }
        } else {
            $monthlyOrders = null;
            $totalOrdersYear = null;
            $ordersPerMonth = null;
            $totalRevenueOrdersYear = null;
            $avgRevenueOrdersPerMonth = null;
            $orderRanks = null;
        }
        
        // ========== TASK STATISTICS ==========
        
        // Tasks as main technician (or all tasks if all users)
        if ($isAllUsers) {
            $mainTechTasksQuery = Task::query();
        } else {
            $mainTechTasksQuery = Task::where('technician_id', $userId);
        }
        $mainTechTasksAllTime = clone $mainTechTasksQuery;
        
        $mainTechTotal = $mainTechTasksAllTime->count();
        $mainTechCompleted = $mainTechTasksAllTime->where('status', 'terminée')->count();
        $mainTechInProgress = $mainTechTasksAllTime->whereIn('status', ['en cours', 'en route'])->count();
        $mainTechCancelled = $mainTechTasksAllTime->where('status', 'annulée')->count();
        $mainTechOnPause = $mainTechTasksAllTime->where('status', 'en pause')->count();
        $mainTechPending = $mainTechTasksAllTime->where('status', 'en attente')->count();
        $mainTechUrgent = $mainTechTasksAllTime->where('urgent', true)->count();
        
        // Task earnings
        $mainTechEarnings = $mainTechTasksAllTime->where('is_paid', true)->sum('amount_paid') ?? 0;
        $paidTasksCount = $mainTechTasksAllTime->where('is_paid', true)->count();
        $avgTaskEarnings = $paidTasksCount > 0 ? $mainTechEarnings / $paidTasksCount : 0;
        
        // Tasks as helping user (or 0 if all users, since we're already counting all tasks)
        if ($isAllUsers) {
            $helpingTasksQuery = Task::query()->whereRaw('1 = 0'); // Empty query
            $helpingTotal = 0;
            $helpingCompleted = 0;
            $allTaskIds = Task::pluck('id')->toArray();
        } else {
            $helpingTasksQuery = Task::whereJsonContains('helping_user_ids', (int)$userId);
            $helpingTasksAllTime = clone $helpingTasksQuery;
            $helpingTotal = $helpingTasksAllTime->count();
            $helpingCompleted = $helpingTasksAllTime->where('status', 'terminée')->count();
            
            // Get all task IDs where user is involved (main tech or helping)
            $allTaskIds = array_unique(array_merge(
                Task::where('technician_id', $userId)->pluck('id')->toArray(),
                Task::whereJsonContains('helping_user_ids', (int)$userId)->pluck('id')->toArray()
            ));
        }
        
        // Tasks participated in (has start_visit event by this user, or all if all users)
        if ($isAllUsers) {
            $tasksWithVisits = TaskEvent::where('event_type', 'start_visit')
                ->select('task_id')
                ->distinct()
                ->pluck('task_id')
                ->toArray();
        } else {
            $tasksWithVisits = TaskEvent::whereIn('task_id', $allTaskIds)
                ->where('user_id', $userId)
                ->where('event_type', 'start_visit')
                ->select('task_id')
                ->distinct()
                ->pluck('task_id')
                ->toArray();
        }
        
        $tasksParticipated = count($tasksWithVisits);
        $tasksNotParticipated = count($allTaskIds) - $tasksParticipated;
        
        // Apply date filter for period-specific stats
        if ($startDate && $endDate) {
            $mainTechTasksFiltered = $applyDateFilter(clone $mainTechTasksQuery, 'task_date');
            $helpingTasksFiltered = $applyDateFilter(clone $helpingTasksQuery, 'task_date');
            
            // Recalculate participated/not participated for filtered period
            $allTaskIdsFiltered = array_unique(array_merge(
                $mainTechTasksFiltered->pluck('id')->toArray(),
                $helpingTasksFiltered->pluck('id')->toArray()
            ));
            
            if ($isAllUsers) {
                $tasksWithVisitsFiltered = TaskEvent::whereIn('task_id', $allTaskIdsFiltered)
                    ->where('event_type', 'start_visit')
                    ->whereBetween('event_time', [$startDate, $endDate])
                    ->select('task_id')
                    ->distinct()
                    ->pluck('task_id')
                    ->toArray();
            } else {
                $tasksWithVisitsFiltered = TaskEvent::whereIn('task_id', $allTaskIdsFiltered)
                    ->where('user_id', $userId)
                    ->where('event_type', 'start_visit')
                    ->whereBetween('event_time', [$startDate, $endDate])
                    ->select('task_id')
                    ->distinct()
                    ->pluck('task_id')
                    ->toArray();
            }
            
            $tasksParticipatedPeriod = count($tasksWithVisitsFiltered);
            $tasksNotParticipatedPeriod = count($allTaskIdsFiltered) - $tasksParticipatedPeriod;
        } else {
            $tasksParticipatedPeriod = null;
            $tasksNotParticipatedPeriod = null;
        }
        
        // Tasks by type
        $tasksByType = $mainTechTasksAllTime
            ->selectRaw('task_type, COUNT(*) as count')
            ->groupBy('task_type')
            ->pluck('count', 'task_type')
            ->toArray();
        
        // Tasks by status
        $tasksByStatus = $mainTechTasksAllTime
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        // Average task completion time
        $completedTasksQuery = Task::where('status', 'terminée');
        if (!$isAllUsers) {
            $completedTasksQuery->where('technician_id', $userId);
        }
        $completedTasksWithDates = $completedTasksQuery
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->get();
        
        $totalCompletionMinutes = 0;
        $completionCount = 0;
        foreach ($completedTasksWithDates as $task) {
            $minutes = $task->started_at->diffInMinutes($task->finished_at);
            $totalCompletionMinutes += $minutes;
            $completionCount++;
        }
        $avgCompletionHours = $completionCount > 0 ? ($totalCompletionMinutes / 60) / $completionCount : 0;
        $avgCompletionDays = $completionCount > 0 ? $avgCompletionHours / 24 : 0;
        
        // ========== DEPLOYMENT STATISTICS ==========
        if ($isAllUsers) {
            $deploymentsAsResponsible = Deployment::query();
            $deploymentsAsDriver = Deployment::query();
            $deploymentsAsTeamMember = Deployment::query();
            $allDeploymentIds = Deployment::pluck('id')->toArray();
        } else {
            $deploymentsAsResponsible = Deployment::where('responsible_id', $userId);
            $deploymentsAsDriver = Deployment::where('driver_id', $userId);
            $deploymentsAsTeamMember = Deployment::whereJsonContains('team_member_ids', (int)$userId);
            
            // Get all deployment IDs where user is involved
            $hosterDeploymentIds = $hasHostersColumn
                ? Deployment::whereJsonContains('hosters', (int)$userId)->pluck('id')->toArray()
                : [];

            $allDeploymentIds = array_unique(array_merge(
                Deployment::where('responsible_id', $userId)->pluck('id')->toArray(),
                Deployment::where('driver_id', $userId)->pluck('id')->toArray(),
                Deployment::whereJsonContains('team_member_ids', (int)$userId)->pluck('id')->toArray(),
                $hosterDeploymentIds
            ));
            
            // Also include deployments where user is involved through tasks
            $taskDeploymentIds = Task::whereIn('id', $allTaskIds)
                ->whereNotNull('deployment_id')
                ->distinct('deployment_id')
                ->pluck('deployment_id')
                ->toArray();
            
            $allDeploymentIds = array_unique(array_merge($allDeploymentIds, $taskDeploymentIds));
        }
        
        $deploymentsResponsibleCount = $deploymentsAsResponsible->count();
        $deploymentsDriverCount = $deploymentsAsDriver->count();
        $deploymentsTeamMemberCount = $deploymentsAsTeamMember->count();
        $totalDeploymentsParticipated = count($allDeploymentIds);
        
        // Tasks completed within deployments
        $tasksInDeployments = Task::whereIn('deployment_id', $allDeploymentIds)
            ->where('status', 'terminée')
            ->count();
        
        // Apply date filter if specified
        if ($startDate && $endDate) {
            $deploymentsResponsibleFiltered = $applyDateFilter(clone $deploymentsAsResponsible, 'deployment_date');
            $deploymentsDriverFiltered = $applyDateFilter(clone $deploymentsAsDriver, 'deployment_date');
            $deploymentsTeamMemberFiltered = $applyDateFilter(clone $deploymentsAsTeamMember, 'deployment_date');
            
            $deploymentsResponsiblePeriod = $deploymentsResponsibleFiltered->count();
            $deploymentsDriverPeriod = $deploymentsDriverFiltered->count();
            $deploymentsTeamMemberPeriod = $deploymentsTeamMemberFiltered->count();
        } else {
            $deploymentsResponsiblePeriod = null;
            $deploymentsDriverPeriod = null;
            $deploymentsTeamMemberPeriod = null;
        }
        
        // ========== VACATION STATISTICS ==========
        if ($isAllUsers) {
            $leaveRequestsQuery = LeaveRequest::whereIn('status', ['accepted', 'on_leave', 'completed']);
        } else {
            $leaveRequestsQuery = LeaveRequest::where('user_id', $userId)
                ->whereIn('status', ['accepted', 'on_leave', 'completed']);
        }
        
        // Vacation days this year
        $vacationDaysThisYear = 0;
        $yearStartForVacation = Carbon::create($currentYear, 1, 1)->startOfDay();
        $yearEndForVacation = Carbon::create($currentYear, 12, 31)->endOfDay();
        
        $vacationRequestsQuery = LeaveRequest::whereIn('status', ['accepted', 'on_leave', 'completed']);
        if (!$isAllUsers) {
            $vacationRequestsQuery->where('user_id', $userId);
        }
        $vacationRequestsThisYear = $vacationRequestsQuery
            ->where(function($q) use ($yearStartForVacation, $yearEndForVacation) {
                $q->whereBetween('start_date', [$yearStartForVacation, $yearEndForVacation])
                  ->orWhereBetween('end_date', [$yearStartForVacation, $yearEndForVacation])
                  ->orWhere(function($q2) use ($yearStartForVacation, $yearEndForVacation) {
                      $q2->where('start_date', '<=', $yearStartForVacation)
                         ->where('end_date', '>=', $yearEndForVacation);
                  });
            })
            ->get();
        
        foreach ($vacationRequestsThisYear as $request) {
            $start = max($request->start_date, $yearStartForVacation);
            $end = min($request->end_date, $yearEndForVacation);
            if ($end >= $start) {
                $vacationDaysThisYear += $start->diffInDays($end) + 1;
            }
        }
        
        // Vacation days this month
        $vacationDaysThisMonth = 0;
        $vacationRequestsQueryMonth = LeaveRequest::whereIn('status', ['accepted', 'on_leave', 'completed']);
        if (!$isAllUsers) {
            $vacationRequestsQueryMonth->where('user_id', $userId);
        }
        $vacationRequestsThisMonth = $vacationRequestsQueryMonth
            ->where(function($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('start_date', [$monthStart, $monthEnd])
                  ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                  ->orWhere(function($q2) use ($monthStart, $monthEnd) {
                      $q2->where('start_date', '<=', $monthStart)
                         ->where('end_date', '>=', $monthEnd);
                  });
            })
            ->get();
        
        foreach ($vacationRequestsThisMonth as $request) {
            $start = max($request->start_date, $monthStart);
            $end = min($request->end_date, $monthEnd);
            if ($end >= $start) {
                $vacationDaysThisMonth += $start->diffInDays($end) + 1;
            }
        }
        
        // Total vacation days all time
        $totalVacationDays = 0;
        $allVacationRequests = $leaveRequestsQuery->get();
        foreach ($allVacationRequests as $request) {
            $totalVacationDays += $request->start_date->diffInDays($request->end_date) + 1;
        }
        
        // Pending vacation requests
        $pendingVacationQuery = LeaveRequest::where('status', 'waiting');
        if (!$isAllUsers) {
            $pendingVacationQuery->where('user_id', $userId);
        }
        $pendingVacationRequests = $pendingVacationQuery->count();
        
        // Vacation by type
        $vacationByType = $leaveRequestsQuery
            ->selectRaw('leave_type, COUNT(*) as count')
            ->groupBy('leave_type')
            ->pluck('count', 'leave_type')
            ->toArray();
        
        // ========== GENERAL STATISTICS (for General Tab) ==========
        // Use selected year for general stats if year is provided
        $yearStartForGeneral = $year ? Carbon::create($year, 1, 1)->startOfDay() : null;
        $yearEndForGeneral = $year ? Carbon::create($year, 12, 31)->endOfDay() : null;
        
        // Total orders (only confirmed/processing or shipped/delivered) - for selected year
        $generalOrdersQuery = Order::whereIn('status', ['processing', 'shipped']);
        if (!$isAllUsers) {
            $generalOrdersQuery->where('created_by', $userId);
        }
        if ($yearStartForGeneral && $yearEndForGeneral) {
            $generalOrdersQuery->whereBetween('created_at', [$yearStartForGeneral, $yearEndForGeneral]);
        }
        $generalTotalOrders = $generalOrdersQuery->count();
        
        // Revenue from orders (only confirmed/processing or shipped/delivered) - for selected year
        $generalOrdersRevenueQuery = Order::whereIn('status', ['processing', 'shipped']);
        if (!$isAllUsers) {
            $generalOrdersRevenueQuery->where('created_by', $userId);
        }
        if ($yearStartForGeneral && $yearEndForGeneral) {
            $generalOrdersRevenueQuery->whereBetween('created_at', [$yearStartForGeneral, $yearEndForGeneral]);
        }
        $generalOrdersRevenue = $generalOrdersRevenueQuery->sum('total_with_tax') ?? 0;
        
        // Total tasks (only completed/terminée) - for selected year
        $generalTasksQuery = Task::where('status', 'terminée');
        if (!$isAllUsers) {
            $generalTasksQuery->where('technician_id', $userId);
        }
        if ($yearStartForGeneral && $yearEndForGeneral) {
            $generalTasksQuery->whereBetween('task_date', [$yearStartForGeneral, $yearEndForGeneral]);
        }
        $mainTechCompletedTaskIds = $generalTasksQuery->pluck('id')->toArray();
        
        // Get tasks where user is a helping user - handle JSON array properly
        $generalHelpingTasksQuery = Task::where('status', 'terminée');
        if ($yearStartForGeneral && $yearEndForGeneral) {
            $generalHelpingTasksQuery->whereBetween('task_date', [$yearStartForGeneral, $yearEndForGeneral]);
        }
        if ($isAllUsers) {
            $helpingCompletedTaskIds = [];
        } else {
            $helpingCompletedTaskIds = $generalHelpingTasksQuery->get()
                ->filter(function($task) use ($userId) {
                    $helpingIds = $task->helping_user_ids ?? [];
                    return is_array($helpingIds) && in_array($userId, $helpingIds);
                })
                ->pluck('id')
                ->toArray();
        }
        
        $generalTotalTasks = count(array_unique(array_merge($mainTechCompletedTaskIds, $helpingCompletedTaskIds)));
        
        // Revenue from tasks (only completed tasks) - for selected year
        $generalTasksRevenueQuery = Task::where('status', 'terminée')
            ->where('is_paid', true);
        if (!$isAllUsers) {
            $generalTasksRevenueQuery->where('technician_id', $userId);
        }
        if ($yearStartForGeneral && $yearEndForGeneral) {
            $generalTasksRevenueQuery->whereBetween('task_date', [$yearStartForGeneral, $yearEndForGeneral]);
        }
        $generalTasksRevenue = $generalTasksRevenueQuery->sum('amount_paid') ?? 0;
        
        // Total vacation days - for selected year (use vacationDaysThisYear if year matches, otherwise calculate)
        if ($year && $year == $currentYear) {
            $generalVacationDays = $vacationDaysThisYear;
        } elseif ($year) {
            // Calculate for the specified year
            $yearStartForVacGeneral = Carbon::create($year, 1, 1)->startOfDay();
            $yearEndForVacGeneral = Carbon::create($year, 12, 31)->endOfDay();
            
            $vacationRequestsForYearQuery = LeaveRequest::whereIn('status', ['accepted', 'on_leave', 'completed']);
            if (!$isAllUsers) {
                $vacationRequestsForYearQuery->where('user_id', $userId);
            }
            $vacationRequestsForYear = $vacationRequestsForYearQuery
                ->where(function($q) use ($yearStartForVacGeneral, $yearEndForVacGeneral) {
                    $q->whereBetween('start_date', [$yearStartForVacGeneral, $yearEndForVacGeneral])
                      ->orWhereBetween('end_date', [$yearStartForVacGeneral, $yearEndForVacGeneral])
                      ->orWhere(function($q2) use ($yearStartForVacGeneral, $yearEndForVacGeneral) {
                          $q2->where('start_date', '<=', $yearStartForVacGeneral)
                             ->where('end_date', '>=', $yearEndForVacGeneral);
                      });
                })
                ->get();
            
            $generalVacationDays = 0;
            foreach ($vacationRequestsForYear as $request) {
                $start = max($request->start_date, $yearStartForVacGeneral);
                $end = min($request->end_date, $yearEndForVacGeneral);
                if ($end >= $start) {
                    $generalVacationDays += $start->diffInDays($end) + 1;
                }
            }
        } else {
            // All time
            $generalVacationDays = $totalVacationDays;
        }
        
        // Total deployments - for selected year
        if ($yearStartForGeneral && $yearEndForGeneral) {
            // Get deployments that overlap with the selected year
            $deploymentIdsForYear = Deployment::where(function($q) use ($yearStartForGeneral, $yearEndForGeneral) {
                    $q->whereBetween('deployment_date', [$yearStartForGeneral, $yearEndForGeneral]);
                })
                ->pluck('id')
                ->toArray();
            
            // Filter by user participation and deployment date
            if ($isAllUsers) {
                $generalDeploymentsAsResponsible = Deployment::whereBetween('deployment_date', [$yearStartForGeneral, $yearEndForGeneral])
                    ->pluck('id')
                    ->toArray();
                $generalDeploymentsAsDriver = [];
                $generalDeploymentsAsTeamMember = [];
                $taskIdsForYear = [];
            } else {
                $generalDeploymentsAsResponsible = Deployment::where('responsible_id', $userId)
                    ->whereBetween('deployment_date', [$yearStartForGeneral, $yearEndForGeneral])
                    ->pluck('id')
                    ->toArray();
                
                $generalDeploymentsAsDriver = Deployment::where('driver_id', $userId)
                    ->whereBetween('deployment_date', [$yearStartForGeneral, $yearEndForGeneral])
                    ->pluck('id')
                    ->toArray();
                
                $generalDeploymentsAsTeamMember = Deployment::whereJsonContains('team_member_ids', (int)$userId)
                    ->whereBetween('deployment_date', [$yearStartForGeneral, $yearEndForGeneral])
                    ->pluck('id')
                    ->toArray();
                
                // Also include deployments through tasks in the year
                $taskIdsForYear = Task::where('technician_id', $userId)
                    ->whereBetween('task_date', [$yearStartForGeneral, $yearEndForGeneral])
                    ->whereNotNull('deployment_id')
                    ->distinct('deployment_id')
                    ->pluck('deployment_id')
                    ->toArray();
            }
            
            $generalTotalDeployments = count(array_unique(array_merge(
                $generalDeploymentsAsResponsible,
                $generalDeploymentsAsDriver,
                $generalDeploymentsAsTeamMember,
                $taskIdsForYear
            )));
        } else {
            // All time (already calculated above)
            $generalTotalDeployments = $totalDeploymentsParticipated;
        }
        
        // ========== ADDITIONAL INSIGHTS ==========
        $completionRate = $mainTechTotal > 0 ? ($mainTechCompleted / $mainTechTotal) * 100 : 0;
        $participationRate = count($allTaskIds) > 0 ? ($tasksParticipated / count($allTaskIds)) * 100 : 0;
        
        // Average orders per month (from all time)
        $firstOrderQuery = Order::query();
        if (!$isAllUsers) {
            $firstOrderQuery->where('created_by', $userId);
        }
        $firstOrder = $firstOrderQuery->orderBy('created_at', 'asc')->first();
        $monthsActive = 1;
        if ($firstOrder) {
            $monthsActive = max(1, Carbon::parse($firstOrder->created_at)->diffInMonths(Carbon::now()) + 1);
        }
        $avgOrdersPerMonth = $monthsActive > 0 ? $totalOrders / $monthsActive : 0;
        
        // Average tasks per month
        $firstTaskQuery = Task::query();
        if (!$isAllUsers) {
            $firstTaskQuery->where('technician_id', $userId);
        }
        $firstTask = $firstTaskQuery->orderBy('task_date', 'asc')->first();
        $monthsActiveTasks = 1;
        if ($firstTask) {
            $monthsActiveTasks = max(1, Carbon::parse($firstTask->task_date)->diffInMonths(Carbon::now()) + 1);
        }
        $avgTasksPerMonth = $monthsActiveTasks > 0 ? $mainTechTotal / $monthsActiveTasks : 0;
        
        // Monthly orders breakdown for selected year (if year is provided)
        $monthlyOrders = [];
        if ($year) {
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                
                // Orders in this month
                $ordersInMonthQuery = Order::whereBetween('created_at', [$monthStart, $monthEnd]);
                if (!$isAllUsers && $userId) {
                    $ordersInMonthQuery->where('created_by', $userId);
                }
                $ordersInMonth = $ordersInMonthQuery->count();
                
                // Revenue from orders in this month
                $revenueInMonthQuery = Order::whereBetween('created_at', [$monthStart, $monthEnd]);
                if (!$isAllUsers && $userId) {
                    $revenueInMonthQuery->where('created_by', $userId);
                }
                $revenueInMonth = $revenueInMonthQuery->sum('total_with_tax') ?? 0;
                
                $monthlyOrders[] = [
                    'month' => $month,
                    'month_name' => Carbon::create($year, $month, 1)->locale('fr_FR')->monthName,
                    'orders' => $ordersInMonth,
                    'revenue' => round($revenueInMonth, 2),
                ];
            }
            
            // Calculate year totals and averages for orders
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
            $daysInYear = $yearStart->diffInDays($yearEnd) + 1;
            
            $totalOrdersYearQuery = Order::whereBetween('created_at', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $totalOrdersYearQuery->where('created_by', $userId);
            }
            $totalOrdersYear = $totalOrdersYearQuery->count();
            
            $totalRevenueOrdersYearQuery = Order::whereBetween('created_at', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $totalRevenueOrdersYearQuery->where('created_by', $userId);
            }
            $totalRevenueOrdersYear = $totalRevenueOrdersYearQuery->sum('total_with_tax') ?? 0;
            
            $ordersPerDay = $daysInYear > 0 ? round($totalOrdersYear / $daysInYear, 2) : 0;
            $avgRevenueOrdersPerMonth = round($totalRevenueOrdersYear / 12, 2);
        } else {
            $monthlyOrders = null;
            $totalOrdersYear = null;
            $ordersPerDay = null;
            $totalRevenueOrdersYear = null;
            $avgRevenueOrdersPerMonth = null;
        }
        
        // Monthly tasks breakdown for selected year (if year is provided)
        $monthlyTasks = [];
        $completedTaskIdsYear = null; // Initialize for use in by_type calculation
        if ($year) {
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                
                // Tasks as main technician in this month (ONLY COMPLETED)
                $mainTechInMonthQuery = Task::where('status', 'terminée')
                    ->whereBetween('task_date', [$monthStart, $monthEnd]);
                if ($taskType) {
                    $mainTechInMonthQuery->where('task_type', $taskType);
                }
                if (!$isAllUsers && $userId) {
                    $mainTechInMonthQuery->where('technician_id', $userId);
                }
                $mainTechInMonth = $mainTechInMonthQuery->count();
                
                // Tasks as helping user in this month (ONLY COMPLETED)
                $helpingInMonthQuery = Task::where('status', 'terminée')
                    ->whereBetween('task_date', [$monthStart, $monthEnd]);
                if ($taskType) {
                    $helpingInMonthQuery->where('task_type', $taskType);
                }
                if ($isAllUsers) {
                    $helpingInMonth = 0; // When all users, we already count all tasks as main tech
                } else {
                    $helpingInMonth = $helpingInMonthQuery->get()
                        ->filter(function($task) use ($userId) {
                            $helpingIds = $task->helping_user_ids ?? [];
                            return is_array($helpingIds) && in_array($userId, $helpingIds);
                        })
                        ->count();
                }
                
                // Revenue from tasks in this month (as main tech, paid tasks)
                $revenueInMonthQuery = Task::where('is_paid', true)
                    ->whereBetween('task_date', [$monthStart, $monthEnd]);
                if ($taskType) {
                    $revenueInMonthQuery->where('task_type', $taskType);
                }
                if (!$isAllUsers && $userId) {
                    $revenueInMonthQuery->where('technician_id', $userId);
                }
                $revenueInMonth = $revenueInMonthQuery->sum('amount_paid') ?? 0;
                
                $monthlyTasks[] = [
                    'month' => $month,
                    'month_name' => Carbon::create($year, $month, 1)->locale('fr_FR')->monthName,
                    'main_tech' => $mainTechInMonth,
                    'helping_user' => $helpingInMonth,
                    'revenue' => round($revenueInMonth, 2),
                ];
            }
            
            // Calculate year totals and averages
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
            $daysInYear = $yearStart->diffInDays($yearEnd) + 1;
            
            // Get unique task IDs (tasks where user is either main tech or helping user) - ONLY COMPLETED TASKS (status = 'terminée')
            // This is used for: Total Tasks, Tasks per Day, Participation Percentage, and Average Visits per Task
            $mainTechTaskIdsQuery = Task::where('status', 'terminée')
                ->whereBetween('task_date', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $mainTechTaskIdsQuery->where('technician_id', $userId);
            }
            $mainTechTaskIds = $mainTechTaskIdsQuery->pluck('id')->toArray();
            
            // Get tasks where user is a helping user - handle JSON array properly
            if ($isAllUsers) {
                $helpingTaskIds = []; // When all users, we already count all tasks as main tech
            } else {
                $helpingTaskIds = Task::where('status', 'terminée')
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->get()
                    ->filter(function($task) use ($userId) {
                        $helpingIds = $task->helping_user_ids ?? [];
                        return is_array($helpingIds) && in_array($userId, $helpingIds);
                    })
                    ->pluck('id')
                    ->toArray();
            }
            
            $completedTaskIdsYear = array_unique(array_merge($mainTechTaskIds, $helpingTaskIds));
            
            // Total Tasks - ONLY counts finished/completed tasks (status = 'terminée')
            $totalTasksYear = count($completedTaskIdsYear);
            
            // Calculate total payment (only from tasks where user is main technician)
            // Since only main technicians receive payment, we filter by technician_id
            $totalPaymentQuery = Task::where('status', 'terminée')
                ->where('is_paid', true)
                ->whereBetween('task_date', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $totalPaymentQuery->where('technician_id', $userId);
            }
            $totalPayment = $totalPaymentQuery->sum('amount_paid') ?? 0;
            
            // Calculate payment rate (percentage of main technician tasks that are paid)
            // Only main technician tasks can be paid
            $mainTechTasksYearQuery = Task::where('status', 'terminée')
                ->whereBetween('task_date', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $mainTechTasksYearQuery->where('technician_id', $userId);
            }
            $mainTechTasksYear = $mainTechTasksYearQuery->count();
            $paidTasksCountQuery = Task::where('status', 'terminée')
                ->where('is_paid', true)
                ->whereBetween('task_date', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $paidTasksCountQuery->where('technician_id', $userId);
            }
            $paidTasksCount = $paidTasksCountQuery->count();
            $paymentRate = $mainTechTasksYear > 0 
                ? round(($paidTasksCount / $mainTechTasksYear) * 100, 2) 
                : 0;
            
            // Tasks per Month - ONLY counts finished/completed tasks
            $tasksPerMonth = round($totalTasksYear / 12, 2);
            $avgRevenuePerMonth = round($totalPayment / 12, 2);
            
            // Performance metrics for the year - ALL use finished/completed tasks only
            $finishedTaskIdsYear = $completedTaskIdsYear;
            $finishedTasksCount = count($finishedTaskIdsYear);
            
            // Count total visits (start_visit events) by this user on finished tasks only
            $totalVisitsQuery = TaskEvent::whereIn('task_id', $finishedTaskIdsYear)
                ->where('event_type', 'start_visit');
            if (!$isAllUsers && $userId) {
                $totalVisitsQuery->where('user_id', $userId);
            }
            $totalVisits = $totalVisitsQuery->count();
            
            // Average Visits per Task - ONLY counts finished/completed tasks
            $avgVisitsPerTask = $finishedTasksCount > 0 ? round($totalVisits / $finishedTasksCount, 2) : 0;
            
            // Calculate average time per visit (only for finished tasks)
            // Get all visit pairs (start_visit and finish_visit) for this user on finished tasks
            $visitDurations = [];
            $userEventsQuery = TaskEvent::whereIn('task_id', $finishedTaskIdsYear)
                ->whereIn('event_type', ['start_visit', 'finish_visit'])
                ->orderBy('event_time', 'asc');
            if (!$isAllUsers && $userId) {
                $userEventsQuery->where('user_id', $userId);
            }
            $userEvents = $userEventsQuery->get();
            
            $startVisitTime = null;
            foreach ($userEvents as $event) {
                if ($event->event_type === 'start_visit') {
                    $startVisitTime = Carbon::parse($event->event_time);
                } elseif ($event->event_type === 'finish_visit' && $startVisitTime) {
                    $finishVisitTime = Carbon::parse($event->event_time);
                    $durationMinutes = $startVisitTime->diffInMinutes($finishVisitTime);
                    if ($durationMinutes > 0) { // Only count valid durations
                        $visitDurations[] = $durationMinutes;
                    }
                    $startVisitTime = null; // Reset for next visit
                }
            }
            
            $avgTimePerVisit = count($visitDurations) > 0 
                ? round(array_sum($visitDurations) / count($visitDurations), 2) 
                : 0; // in minutes
            
            // Termination percentage is 100% since we only count completed tasks now
            // But we need to calculate it based on all tasks (completed + non-completed)
            $allMainTechTaskIdsQuery = Task::whereBetween('task_date', [$yearStart, $yearEnd]);
            if (!$isAllUsers && $userId) {
                $allMainTechTaskIdsQuery->where('technician_id', $userId);
            }
            $allMainTechTaskIds = $allMainTechTaskIdsQuery->pluck('id')->toArray();
            
            // Get all tasks (regardless of status) where user is a helping user
            if ($isAllUsers) {
                $allHelpingTaskIds = []; // When all users, we already count all tasks as main tech
            } else {
                $allHelpingTaskIds = Task::whereBetween('task_date', [$yearStart, $yearEnd])
                    ->get()
                    ->filter(function($task) use ($userId) {
                        $helpingIds = $task->helping_user_ids ?? [];
                        return is_array($helpingIds) && in_array($userId, $helpingIds);
                    })
                    ->pluck('id')
                    ->toArray();
            }
            
            $allTaskIdsYear = array_unique(array_merge($allMainTechTaskIds, $allHelpingTaskIds));
            $allTasksCount = count($allTaskIdsYear);
            $completedTasksYear = count($completedTaskIdsYear);
            $terminationPercentage = $allTasksCount > 0 
                ? round(($completedTasksYear / $allTasksCount) * 100, 2) 
                : 0;
            
            // Participation Percentage - ONLY counts finished/completed tasks
            // This calculates: (finished tasks with visits / total finished tasks) * 100
            // Count distinct tasks with start_visit events for this user
            // Only count tasks that are in the finishedTaskIdsYear array
            if (count($finishedTaskIdsYear) > 0) {
                // Split large arrays into chunks to avoid SQL parameter limits
                $chunkSize = 1000;
                $participatedTaskIds = [];
                $chunks = array_chunk($finishedTaskIdsYear, $chunkSize);
                
                foreach ($chunks as $chunk) {
                    // Use selectRaw with DISTINCT for better database compatibility
                    $chunkTaskIdsQuery = TaskEvent::whereIn('task_id', $chunk)
                        ->where('event_type', 'start_visit');
                    if (!$isAllUsers && $userId) {
                        $chunkTaskIdsQuery->where('user_id', $userId);
                    }
                    $chunkTaskIds = $chunkTaskIdsQuery->selectRaw('DISTINCT task_id')
                        ->pluck('task_id')
                        ->toArray();
                    $participatedTaskIds = array_merge($participatedTaskIds, $chunkTaskIds);
                }
                
                $participatedTasksYear = count(array_unique($participatedTaskIds));
            } else {
                $participatedTasksYear = 0;
            }
            
            // Calculate using the same finished tasks count
            // Debug: If participation is wrong, the issue might be in $finishedTasksCount calculation
            $participationPercentage = $finishedTasksCount > 0 
                ? round(($participatedTasksYear / $finishedTasksCount) * 100, 2) 
                : 0;
            
            // Calculate rankings for all users for the year
            $allUsers = User::where('is_blocked', false)->get();
            $userMetrics = [];
            
            foreach ($allUsers as $user) {
                $uid = $user->id;
                
                // Get completed task IDs for this user in the year (ONLY COMPLETED TASKS for total_tasks and tasks_per_day)
                $userMainTechTaskIds = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->pluck('id')->toArray();
                
                // Get tasks where user is a helping user - handle JSON array properly
                $userHelpingTaskIds = Task::where('status', 'terminée')
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->get()
                    ->filter(function($task) use ($uid) {
                        $helpingIds = $task->helping_user_ids ?? [];
                        return is_array($helpingIds) && in_array($uid, $helpingIds);
                    })
                    ->pluck('id')
                    ->toArray();
                
                $userCompletedTaskIds = array_unique(array_merge($userMainTechTaskIds, $userHelpingTaskIds));
                
                // Include all users, even those with no tasks (they'll have 0 values)
                
                $userTotalTasks = count($userCompletedTaskIds); // Only completed tasks
                $userFinishedTasks = $userCompletedTaskIds;
                $userFinishedCount = count($userFinishedTasks);
                
                // Total tasks per month (based on completed tasks only)
                $userTasksPerMonth = round($userTotalTasks / 12, 2);
                
                // Calculate total payment for this user (only from main technician tasks)
                $userTotalPayment = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->where('is_paid', true)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->sum('amount_paid') ?? 0;
                
                // Calculate payment rate for this user (only main technician tasks)
                $userMainTechTasksYear = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->count();
                $userPaidTasksCount = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->where('is_paid', true)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->count();
                $userPaymentRate = $userMainTechTasksYear > 0 
                    ? round(($userPaidTasksCount / $userMainTechTasksYear) * 100, 2) 
                    : 0;
                
                // Revenue (same as total payment)
                $userRevenue = $userTotalPayment;
                $userAvgRevenuePerMonth = round($userRevenue / 12, 2);
                $userAllMainTechTaskIds = Task::where('technician_id', $uid)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->pluck('id')->toArray();
                
                // Get all tasks (regardless of status) where user is a helping user
                $userAllHelpingTaskIds = Task::whereBetween('task_date', [$yearStart, $yearEnd])
                    ->get()
                    ->filter(function($task) use ($uid) {
                        $helpingIds = $task->helping_user_ids ?? [];
                        return is_array($helpingIds) && in_array($uid, $helpingIds);
                    })
                    ->pluck('id')
                    ->toArray();
                
                $userAllTaskIds = array_unique(array_merge($userAllMainTechTaskIds, $userAllHelpingTaskIds));
                $userAllTasksCount = count($userAllTaskIds);
                
                // Performance metrics (only for finished tasks)
                $userFinishedTaskIds = $userFinishedTasks;
                $userTotalVisits = TaskEvent::whereIn('task_id', $userFinishedTaskIds)
                    ->where('user_id', $uid)
                    ->where('event_type', 'start_visit')
                    ->count();
                $userAvgVisitsPerTask = $userFinishedCount > 0 ? round($userTotalVisits / $userFinishedCount, 2) : 0;
                
                // Average time per visit
                $userVisitDurations = [];
                $userVisitEvents = TaskEvent::whereIn('task_id', $userFinishedTaskIds)
                    ->where('user_id', $uid)
                    ->whereIn('event_type', ['start_visit', 'finish_visit'])
                    ->orderBy('event_time', 'asc')
                    ->get();
                
                $userStartVisitTime = null;
                foreach ($userVisitEvents as $event) {
                    if ($event->event_type === 'start_visit') {
                        $userStartVisitTime = Carbon::parse($event->event_time);
                    } elseif ($event->event_type === 'finish_visit' && $userStartVisitTime) {
                        $userFinishVisitTime = Carbon::parse($event->event_time);
                        $userDurationMinutes = $userStartVisitTime->diffInMinutes($userFinishVisitTime);
                        if ($userDurationMinutes > 0) {
                            $userVisitDurations[] = $userDurationMinutes;
                        }
                        $userStartVisitTime = null;
                    }
                }
                $userAvgTimePerVisit = count($userVisitDurations) > 0 
                    ? round(array_sum($userVisitDurations) / count($userVisitDurations), 2) 
                    : 0;
                
                // Termination percentage (completed / all tasks)
                $userTerminationPercentage = $userAllTasksCount > 0 
                    ? round(($userFinishedCount / $userAllTasksCount) * 100, 2) 
                    : 0;
                
                // Participation percentage
                $userTasksWithVisits = TaskEvent::whereIn('task_id', $userFinishedTaskIds)
                    ->where('user_id', $uid)
                    ->where('event_type', 'start_visit')
                    ->select('task_id')
                    ->distinct()
                    ->pluck('task_id')
                    ->toArray();
                $userParticipatedCount = count($userTasksWithVisits);
                $userParticipationPercentage = $userFinishedCount > 0 
                    ? round(($userParticipatedCount / $userFinishedCount) * 100, 2) 
                    : 0;
                
                $userMetrics[] = [
                    'user_id' => $uid,
                    'total_tasks' => $userTotalTasks,
                    'tasks_per_month' => $userTasksPerMonth,
                    'total_payment' => round($userTotalPayment, 2),
                    'payment_rate' => $userPaymentRate,
                    'total_revenue' => round($userRevenue, 2),
                    'avg_revenue_per_month' => $userAvgRevenuePerMonth,
                    'participation_percentage' => $userParticipationPercentage,
                    'avg_visits_per_task' => $userAvgVisitsPerTask,
                    'avg_time_per_visit' => $userAvgTimePerVisit,
                    'termination_percentage' => $userTerminationPercentage,
                ];
            }
            
            // Calculate ranks
            $ranks = [
                'total_tasks' => null,
                'tasks_per_month' => null,
                'total_payment' => null,
                'payment_rate' => null,
                'total_revenue' => null,
                'avg_revenue_per_month' => null,
                'participation_percentage' => null,
                'avg_visits_per_task' => null,
                'avg_time_per_visit' => null, // Lower is better, so reverse sort
                'termination_percentage' => null,
            ];
            
            if (!empty($userMetrics)) {
                // Helper function to calculate rank
                $calculateRank = function($metricsArray, $targetUserId, $field, $ascending = false, $zeroLast = false) {
                    // Create a fresh copy of the array for this calculation
                    $sortedMetrics = [];
                    foreach ($metricsArray as $item) {
                        $value = (float)($item[$field] ?? 0);
                        $sortedMetrics[] = [
                            'user_id' => $item['user_id'],
                            'value' => $value
                        ];
                    }
                    
                    // Sort by value
                    usort($sortedMetrics, function($a, $b) use ($ascending, $zeroLast) {
                        $valA = $a['value'];
                        $valB = $b['value'];
                        
                        // Handle zero values for special cases (e.g., avg_time_per_visit with no visits)
                        if ($zeroLast) {
                            if ($valA == 0 && $valB != 0) return 1; // A goes last
                            if ($valA != 0 && $valB == 0) return -1; // B goes last
                            if ($valA == 0 && $valB == 0) return 0; // Keep order
                        }
                        
                        if ($ascending) {
                            return $valA <=> $valB;
                        }
                        return $valB <=> $valA;
                    });
                    
                    // Calculate ranks with proper tie handling
                    $rank = 1;
                    $targetRank = null;
                    
                    for ($i = 0; $i < count($sortedMetrics); $i++) {
                        // If this is not the first item and value changed, update rank
                        if ($i > 0) {
                            $prevValue = $sortedMetrics[$i - 1]['value'];
                            $currValue = $sortedMetrics[$i]['value'];
                            
                            // Check if value changed (with tolerance for floating point)
                            if (abs($currValue - $prevValue) > 0.0001) {
                                $rank = $i + 1;
                            }
                        }
                        
                        // Check if this is the target user (ensure both are int for comparison)
                        $currentUserId = (int)$sortedMetrics[$i]['user_id'];
                        $targetUserIdInt = (int)$targetUserId;
                        
                        if ($currentUserId === $targetUserIdInt) {
                            $targetRank = $rank;
                            break;
                        }
                    }
                    
                    // If user not found, return null (shouldn't happen, but safety check)
                    return $targetRank;
                };
                
                // Calculate ranks for each metric (skip if all users)
                if (!$isAllUsers && $userId) {
                    $ranks['total_tasks'] = $calculateRank($userMetrics, $userId, 'total_tasks', false);
                    $ranks['tasks_per_month'] = $calculateRank($userMetrics, $userId, 'tasks_per_month', false);
                    $ranks['total_payment'] = $calculateRank($userMetrics, $userId, 'total_payment', false);
                    $ranks['payment_rate'] = $calculateRank($userMetrics, $userId, 'payment_rate', false);
                    $ranks['total_revenue'] = $calculateRank($userMetrics, $userId, 'total_revenue', false);
                    $ranks['avg_revenue_per_month'] = $calculateRank($userMetrics, $userId, 'avg_revenue_per_month', false);
                    $ranks['participation_percentage'] = $calculateRank($userMetrics, $userId, 'participation_percentage', false);
                    $ranks['avg_visits_per_task'] = $calculateRank($userMetrics, $userId, 'avg_visits_per_task', false);
                    // For avg_time_per_visit: lower is better, but 0 (no visits) should rank last
                    $ranks['avg_time_per_visit'] = $calculateRank($userMetrics, $userId, 'avg_time_per_visit', true, true);
                    $ranks['termination_percentage'] = $calculateRank($userMetrics, $userId, 'termination_percentage', false);
                }
            }
        } else {
            $monthlyTasks = null;
            $totalTasksYear = null;
            $tasksPerMonth = null;
            $totalPayment = null;
            $paymentRate = null;
            $avgRevenuePerMonth = null;
            $avgVisitsPerTask = null;
            $avgTimePerVisit = null;
            $terminationPercentage = null;
            $participationPercentage = null;
            $ranks = null;
        }
        
        // Calculate tasks by type for yearly breakdown
        $tasksByTypeYear = [];
        if ($year) {
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
            
            if ($isAllUsers) {
                // For all users, query all completed tasks for the year
                $tasksByTypeYear = Task::where('status', 'terminée')
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->whereNotNull('task_type')
                    ->selectRaw('task_type, COUNT(*) as count')
                    ->groupBy('task_type')
                    ->pluck('count', 'task_type')
                    ->toArray();
            } else {
                // For single user, use the completed task IDs (includes main tech and helping user tasks)
                if ($completedTaskIdsYear && !empty($completedTaskIdsYear)) {
                    $tasksByTypeYear = Task::whereIn('id', $completedTaskIdsYear)
                        ->whereNotNull('task_type')
                        ->selectRaw('task_type, COUNT(*) as count')
                        ->groupBy('task_type')
                        ->pluck('count', 'task_type')
                        ->toArray();
                }
            }
        }
        
        // ========== DEPLOYMENT STATS (for Deployments sub-tab) ==========
        $deploymentStatsYearly = null;
        if ($year) {
            $yearStartDeploy = Carbon::create($year, 1, 1)->startOfDay();
            $yearEndDeploy = Carbon::create($year, 12, 31)->endOfDay();
            if ($isAllUsers) {
                $deploymentIdsForStatsYear = Deployment::whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray();
            } else {
                $hosterDeploymentIdsForYear = $hasHostersColumn
                    ? Deployment::whereJsonContains('hosters', (int)$userId)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray()
                    : [];

                $deploymentIdsForStatsYear = array_unique(array_merge(
                    Deployment::where('responsible_id', $userId)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray(),
                    Deployment::where('driver_id', $userId)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray(),
                    Deployment::whereJsonContains('team_member_ids', (int)$userId)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray(),
                    $hosterDeploymentIdsForYear
                ));
            }
            $totalDeploymentsYear = count($deploymentIdsForStatsYear);
            // Total time on deployment: from user's 'joined' to deployment 'end' (per deployment), sum in minutes
            $totalTimeOnDeploymentMinutes = 0;
            if (!empty($deploymentIdsForStatsYear)) {
                if ($isAllUsers) {
                    $allUserIds = User::where('is_blocked', false)->pluck('id')->toArray();
                    foreach ($allUserIds as $uid) {
                        foreach ($deploymentIdsForStatsYear as $depId) {
                            $joined = DeploymentEvent::where('deployment_id', $depId)->where('user_id', $uid)->where('event_type', DeploymentEvent::TYPE_JOINED)->orderBy('event_time')->first();
                            $endEv = DeploymentEvent::where('deployment_id', $depId)->where('event_type', DeploymentEvent::TYPE_END)->orderByDesc('event_time')->first();
                            if ($joined && $endEv && $joined->event_time && $endEv->event_time) {
                                $endTime = Carbon::parse($endEv->event_time);
                                $joinTime = Carbon::parse($joined->event_time);
                                if ($endTime->gt($joinTime)) {
                                    $totalTimeOnDeploymentMinutes += $joinTime->diffInMinutes($endTime);
                                }
                            }
                        }
                    }
                } else {
                    foreach ($deploymentIdsForStatsYear as $depId) {
                        $joined = DeploymentEvent::where('deployment_id', $depId)->where('user_id', $userId)->where('event_type', DeploymentEvent::TYPE_JOINED)->orderBy('event_time')->first();
                        $endEv = DeploymentEvent::where('deployment_id', $depId)->where('event_type', DeploymentEvent::TYPE_END)->orderByDesc('event_time')->first();
                        if ($joined && $endEv && $joined->event_time && $endEv->event_time) {
                            $endTime = Carbon::parse($endEv->event_time);
                            $joinTime = Carbon::parse($joined->event_time);
                            if ($endTime->gt($joinTime)) {
                                $totalTimeOnDeploymentMinutes += $joinTime->diffInMinutes($endTime);
                            }
                        }
                    }
                }
            }
            $deploymentExpensesTotal = 0;
            if (!empty($deploymentIdsForStatsYear)) {
                $deploymentExpensesTotal = DeploymentExpense::whereIn('deployment_id', $deploymentIdsForStatsYear)->sum('amount');
            }
            $averageExpensesPerDeployment = $totalDeploymentsYear > 0
                ? round((float) $deploymentExpensesTotal / $totalDeploymentsYear, 2)
                : 0;
            $deploymentStatsYearly = [
                'total_deployments' => $totalDeploymentsYear,
                'total_time_on_deployment_minutes' => $totalTimeOnDeploymentMinutes,
                'total_time_on_deployment_hours' => round($totalTimeOnDeploymentMinutes / 60, 2),
                'deployment_expenses' => round((float) $deploymentExpensesTotal, 2),
                'average_expenses_per_deployment' => $averageExpensesPerDeployment,
            ];
            // Deployment ranks (for selected user vs all users)
            $deploymentRanks = null;
            if (!$isAllUsers && $userId) {
                $allUsersForDeploy = User::where('is_blocked', false)->get();
                $deploymentUserMetrics = [];
                foreach ($allUsersForDeploy as $user) {
                    $uid = $user->id;
                    $hosterDeploymentIdsForUser = $hasHostersColumn
                        ? Deployment::whereJsonContains('hosters', (int)$uid)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray()
                        : [];

                    $depIds = array_unique(array_merge(
                        Deployment::where('responsible_id', $uid)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray(),
                        Deployment::where('driver_id', $uid)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray(),
                        Deployment::whereJsonContains('team_member_ids', (int)$uid)->whereBetween('deployment_date', [$yearStartDeploy, $yearEndDeploy])->pluck('id')->toArray(),
                        $hosterDeploymentIdsForUser
                    ));
                    $totalDep = count($depIds);
                    $timeMinutes = 0;
                    foreach ($depIds as $depId) {
                        $joined = DeploymentEvent::where('deployment_id', $depId)->where('user_id', $uid)->where('event_type', DeploymentEvent::TYPE_JOINED)->orderBy('event_time')->first();
                        $endEv = DeploymentEvent::where('deployment_id', $depId)->where('event_type', DeploymentEvent::TYPE_END)->orderByDesc('event_time')->first();
                        if ($joined && $endEv && $joined->event_time && $endEv->event_time) {
                            $endTime = Carbon::parse($endEv->event_time);
                            $joinTime = Carbon::parse($joined->event_time);
                            if ($endTime->gt($joinTime)) {
                                $timeMinutes += $joinTime->diffInMinutes($endTime);
                            }
                        }
                    }
                    $expenses = !empty($depIds) ? DeploymentExpense::whereIn('deployment_id', $depIds)->sum('amount') : 0;
                    $avgExpenses = $totalDep > 0 ? round((float) $expenses / $totalDep, 2) : 0;
                    $deploymentUserMetrics[] = [
                        'user_id' => $uid,
                        'total_deployments' => $totalDep,
                        'total_time_on_deployment_minutes' => $timeMinutes,
                        'deployment_expenses' => round((float) $expenses, 2),
                        'average_expenses_per_deployment' => $avgExpenses,
                    ];
                }
                $calculateRank = function ($metricsArray, $targetUserId, $field) {
                    $sorted = collect($metricsArray)->sortByDesc($field)->values()->all();
                    $rank = 1;
                    $prevVal = null;
                    foreach ($sorted as $i => $row) {
                        $currVal = (float)($row[$field] ?? 0);
                        if ($prevVal !== null && abs($currVal - $prevVal) > 0.0001) {
                            $rank = $i + 1;
                        }
                        if ((int)$row['user_id'] === (int)$targetUserId) {
                            return $rank;
                        }
                        $prevVal = $currVal;
                    }
                    return null;
                };
                $deploymentRanks = [
                    'total_deployments' => $calculateRank($deploymentUserMetrics, $userId, 'total_deployments'),
                    'total_time_on_deployment_minutes' => $calculateRank($deploymentUserMetrics, $userId, 'total_time_on_deployment_minutes'),
                    'deployment_expenses' => $calculateRank($deploymentUserMetrics, $userId, 'deployment_expenses'),
                    'average_expenses_per_deployment' => $calculateRank($deploymentUserMetrics, $userId, 'average_expenses_per_deployment'),
                ];
            }
        }
        
        return response()->json([
            'orders' => [
                'total' => $totalOrders,
                'total_revenue' => round($totalRevenue, 2),
                'avg_order_value' => round($avgOrderValue, 2),
                'this_period' => $ordersThisPeriod,
                'revenue_this_period' => round($revenueThisPeriod, 2),
                'this_year' => $ordersThisYear,
                'revenue_this_year' => round($revenueThisYear, 2),
                'this_month' => $ordersThisMonth,
                'revenue_this_month' => round($revenueThisMonth, 2),
                'by_status' => $ordersByStatus,
                'yearly_breakdown' => [
                    'monthly' => $monthlyOrders,
                    'total_orders' => $totalOrdersYear ?? null,
                    'orders_per_month' => $ordersPerMonth ?? null,
                    'total_revenue' => $totalRevenueOrdersYear ? round($totalRevenueOrdersYear, 2) : null,
                    'avg_revenue_per_month' => $avgRevenueOrdersPerMonth ?? null,
                ],
                'ranks' => $orderRanks,
            ],
            'tasks' => [
                'main_technician' => [
                    'total' => $mainTechTotal,
                    'completed' => $mainTechCompleted,
                    'in_progress' => $mainTechInProgress,
                    'cancelled' => $mainTechCancelled,
                    'on_pause' => $mainTechOnPause,
                    'pending' => $mainTechPending,
                    'urgent' => $mainTechUrgent,
                    'total_earnings' => round($mainTechEarnings, 2),
                    'avg_earnings' => round($avgTaskEarnings, 2),
                ],
                'helping_user' => [
                    'total' => $helpingTotal,
                    'completed' => $helpingCompleted,
                ],
                'participation' => [
                    'participated' => $tasksParticipated,
                    'not_participated' => $tasksNotParticipated,
                    'participated_period' => $tasksParticipatedPeriod,
                    'not_participated_period' => $tasksNotParticipatedPeriod,
                ],
                'general' => [
                    'by_type' => $tasksByType,
                    'by_status' => $tasksByStatus,
                    'avg_completion_hours' => round($avgCompletionHours, 2),
                    'avg_completion_days' => round($avgCompletionDays, 2),
                ],
                'yearly_breakdown' => [
                    'monthly' => $monthlyTasks,
                    'total_tasks' => $totalTasksYear,
                    'tasks_per_month' => $tasksPerMonth,
                    'total_payment' => $totalPayment ? round($totalPayment, 2) : null,
                    'payment_rate' => $paymentRate,
                    'paid_tasks_count' => $paidTasksCount ?? 0,
                    'main_tech_tasks_count' => $mainTechTasksYear ?? 0,
                    'total_revenue' => $totalPayment ? round($totalPayment, 2) : null,
                    'avg_revenue_per_month' => $avgRevenuePerMonth,
                    'by_type' => $tasksByTypeYear,
                ],
                'performance' => [
                    'participation_percentage' => $participationPercentage,
                    'participated_tasks_count' => $participatedTasksYear ?? 0,
                    'participation_finished_tasks_count' => $finishedTasksCount ?? 0,
                    'avg_visits_per_task' => $avgVisitsPerTask,
                    'avg_time_per_visit' => $avgTimePerVisit, // in minutes
                    'termination_percentage' => $terminationPercentage,
                    'finished_tasks_count' => $completedTasksYear,
                    'all_tasks_count' => $allTasksCount,
                ],
                'ranks' => $ranks,
            ],
            'deployments' => [
                'as_responsible' => $deploymentsResponsibleCount,
                'as_driver' => $deploymentsDriverCount,
                'as_team_member' => $deploymentsTeamMemberCount,
                'total_participated' => $totalDeploymentsParticipated,
                'tasks_completed_in_deployments' => $tasksInDeployments,
                'as_responsible_period' => $deploymentsResponsiblePeriod,
                'as_driver_period' => $deploymentsDriverPeriod,
                'as_team_member_period' => $deploymentsTeamMemberPeriod,
                'yearly_breakdown' => $deploymentStatsYearly,
                'ranks' => $deploymentRanks ?? null,
            ],
            'vacations' => [
                'days_this_year' => $vacationDaysThisYear,
                'days_this_month' => $vacationDaysThisMonth,
                'total_days' => $totalVacationDays,
                'pending_requests' => $pendingVacationRequests,
                'by_type' => $vacationByType,
            ],
            'insights' => [
                'completion_rate' => round($completionRate, 2),
                'participation_rate' => round($participationRate, 2),
                'avg_orders_per_month' => round($avgOrdersPerMonth, 2),
                'avg_tasks_per_month' => round($avgTasksPerMonth, 2),
            ],
            'general' => [
                'total_orders' => $generalTotalOrders,
                'orders_revenue' => round($generalOrdersRevenue, 2),
                'total_tasks' => $generalTotalTasks,
                'tasks_revenue' => round($generalTasksRevenue, 2),
                'total_vacation_days' => $generalVacationDays,
                'total_deployments' => $generalTotalDeployments,
            ],
        ]);
    }

    public function suiviProfile(Request $request)
    {
        $userId = (int)$request->query('user_id');
        
        if (!$userId) {
            return response()->json(['error' => 'User ID required'], 400);
        }
        
        $user = User::with([
            'profile',
            'city',
            'image',
            'experiences',
            'educations',
            'certifications.image'
        ])->findOrFail($userId);
        
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone_number,
                'address' => $user->address,
                'city' => $user->city ? $user->city->name : null,
                'profile' => $user->profile ? $user->profile->profile_name : null,
                'image' => $user->image && $user->image->image_name ? MediaUrl::image($user->image->image_name) : null,
            ],
            'experiences' => $user->experiences->map(function($exp) {
                return [
                    'id' => $exp->id,
                    'job_title' => $exp->job_title,
                    'company_name' => $exp->company_name,
                    'location' => $exp->location,
                    'start_date' => $exp->start_date ? $exp->start_date->format('Y-m-d') : null,
                    'end_date' => $exp->end_date ? $exp->end_date->format('Y-m-d') : null,
                    'is_current' => $exp->is_current,
                    'description' => $exp->description,
                ];
            }),
            'educations' => $user->educations->map(function($edu) {
                return [
                    'id' => $edu->id,
                    'school_name' => $edu->school_name,
                    'degree' => $edu->degree,
                    'field_of_study' => $edu->field_of_study,
                    'location' => $edu->location,
                    'start_date' => $edu->start_date ? $edu->start_date->format('Y-m-d') : null,
                    'end_date' => $edu->end_date ? $edu->end_date->format('Y-m-d') : null,
                    'is_current' => $edu->is_current,
                    'description' => $edu->description,
                ];
            }),
            'certifications' => $user->certifications->map(function($cert) {
                return [
                    'id' => $cert->id,
                    'name' => $cert->name,
                    'issuing_organization' => $cert->issuing_organization,
                    'issue_date' => $cert->issue_date ? $cert->issue_date->format('Y-m-d') : null,
                    'expiry_date' => $cert->expiry_date ? $cert->expiry_date->format('Y-m-d') : null,
                    'credential_id' => $cert->credential_id,
                    'credential_url' => $cert->credential_url,
                    'description' => $cert->description,
                    'image' => $cert->image ? MediaUrl::image($cert->image->image_name) : null,
                ];
            }),
        ]);
    }

    public function suiviDeployments(Request $request)
    {
        $userId = (int)$request->query('user_id');
        $year = $request->query('year', Carbon::now()->year);
        $year = (int) $year;
        
        if (!$userId) {
            return response()->json(['error' => 'User ID required'], 400);
        }
        
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        
        // Get deployments where user is involved (responsible, driver, team member, or through tasks)
        $deploymentsAsResponsible = Deployment::where('responsible_id', $userId)
            ->whereBetween('deployment_date', [$yearStart, $yearEnd])
            ->with(['city', 'responsible.image', 'driver.image'])
            ->get();
        
        $deploymentsAsDriver = Deployment::where('driver_id', $userId)
            ->whereBetween('deployment_date', [$yearStart, $yearEnd])
            ->with(['city', 'responsible.image', 'driver.image'])
            ->get();
        
        $deploymentsAsTeamMember = Deployment::whereJsonContains('team_member_ids', (int)$userId)
            ->whereBetween('deployment_date', [$yearStart, $yearEnd])
            ->with(['city', 'responsible.image', 'driver.image'])
            ->get();
        
        // Get deployments through tasks
        $taskIdsForYear = Task::where('technician_id', $userId)
            ->whereBetween('task_date', [$yearStart, $yearEnd])
            ->whereNotNull('deployment_id')
            ->distinct('deployment_id')
            ->pluck('deployment_id')
            ->toArray();
        
        $deploymentsThroughTasks = Deployment::whereIn('id', $taskIdsForYear)
            ->whereBetween('deployment_date', [$yearStart, $yearEnd])
            ->with(['city', 'responsible.image', 'driver.image'])
            ->get();
        
        // Merge all deployments and remove duplicates
        $allDeployments = $deploymentsAsResponsible
            ->merge($deploymentsAsDriver)
            ->merge($deploymentsAsTeamMember)
            ->merge($deploymentsThroughTasks)
            ->unique('id')
            ->sortBy('deployment_date')
            ->values();
        
        $deployments = $allDeployments->map(function($deployment) use ($userId) {
            $role = [];
            if ($deployment->responsible_id == $userId) {
                $role[] = 'Responsable';
            }
            if ($deployment->driver_id == $userId) {
                $role[] = 'Chauffeur';
            }
            if (is_array($deployment->team_member_ids) && in_array($userId, $deployment->team_member_ids)) {
                $role[] = 'Membre d\'équipe';
            }
            // Check if user has tasks in this deployment
            $hasTasks = Task::where('technician_id', $userId)
                ->where('deployment_id', $deployment->id)
                ->exists();
            if ($hasTasks && !in_array('Membre d\'équipe', $role)) {
                $role[] = 'Via tâches';
            }
            
            return [
                'id' => $deployment->id,
                'title' => $deployment->title,
                'deployment_date' => $deployment->deployment_date->format('d/m/Y'),
                'city' => $deployment->city ? $deployment->city->name : null,
                'description' => $deployment->description,
                'role' => implode(', ', $role),
                'responsible_name' => $deployment->responsible ? $deployment->responsible->first_name . ' ' . $deployment->responsible->last_name : null,
                'driver_name' => $deployment->driver ? $deployment->driver->first_name . ' ' . $deployment->driver->last_name : null,
                'team_members_count' => is_array($deployment->team_member_ids) ? count($deployment->team_member_ids) : 0,
            ];
        });
        
        return response()->json([
            'success' => true,
            'year' => $year,
            'total_deployments' => $deployments->count(),
            'deployments' => $deployments,
        ]);
    }

    public function deploymentDetails($id, Request $request)
    {
        $userId = (int)$request->query('user_id');
        
        if (!$userId) {
            return response()->json(['error' => 'User ID required'], 400);
        }
        
        $deployment = Deployment::with(['city', 'responsible.image', 'driver.image', 'tasks.client', 'tasks.technician.image', 'events.user.image'])
            ->findOrFail($id);
        
        // Get team members
        $teamMembers = [];
        if ($deployment->team_member_ids && is_array($deployment->team_member_ids)) {
            $teamMembers = User::whereIn('id', $deployment->team_member_ids)
                ->with('image')
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'image' => $user->image && $user->image->image_name 
                            ? MediaUrl::image($user->image->image_name) 
                            : 'https://ui-avatars.com/api/?name=' . urlencode($user->first_name . ' ' . $user->last_name),
                    ];
                });
        }
        
        // Get user's role in this deployment
        $userRole = [];
        if ($deployment->responsible_id == $userId) {
            $userRole[] = 'Responsable';
        }
        if ($deployment->driver_id == $userId) {
            $userRole[] = 'Chauffeur';
        }
        if (is_array($deployment->team_member_ids) && in_array($userId, $deployment->team_member_ids)) {
            $userRole[] = 'Membre d\'équipe';
        }
        $userTasksCount = Task::where('technician_id', $userId)
            ->where('deployment_id', $deployment->id)
            ->count();
        if ($userTasksCount > 0) {
            $userRole[] = 'Via tâches (' . $userTasksCount . ' tâche' . ($userTasksCount > 1 ? 's' : '') . ')';
        }
        
        // Get user's tasks in this deployment
        $userTasks = Task::where('technician_id', $userId)
            ->where('deployment_id', $deployment->id)
            ->with(['client', 'technician.image'])
            ->get()
            ->map(function($task) {
                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'task_type' => $task->task_type,
                    'status' => $task->status,
                    'task_date' => $task->task_date ? $task->task_date->format('d/m/Y') : null,
                    'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                ];
            });
        
        $hostersDetail = $deployment->getHosters()->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'image' => $user->image && $user->image->image_name ? MediaUrl::image($user->image->image_name) : null,
            ];
        })->values()->all();

        $events = $deployment->events->map(function($event) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'user_id' => $event->user_id,
                'user_name' => $event->user ? $event->user->first_name . ' ' . $event->user->last_name : null,
                'user_image' => $event->user && $event->user->image && $event->user->image->image_name ? MediaUrl::image($event->user->image->image_name) : null,
                'event_time' => $event->event_time ? $event->event_time->toIso8601String() : $event->created_at->toIso8601String(),
                'created_at' => $event->created_at->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'deployment' => [
                'id' => $deployment->id,
                'title' => $deployment->title,
                'deployment_date' => $deployment->deployment_date->format('d/m/Y'),
                'city' => $deployment->city ? $deployment->city->name : null,
                'description' => $deployment->description,
                'responsible_name' => $deployment->responsible ? $deployment->responsible->first_name . ' ' . $deployment->responsible->last_name : null,
                'responsible_image' => $deployment->responsible && $deployment->responsible->image && $deployment->responsible->image->image_name 
                    ? MediaUrl::image($deployment->responsible->image->image_name) 
                    : ($deployment->responsible ? 'https://ui-avatars.com/api/?name=' . urlencode($deployment->responsible->first_name . ' ' . $deployment->responsible->last_name) : null),
                'driver_name' => $deployment->driver ? $deployment->driver->first_name . ' ' . $deployment->driver->last_name : null,
                'driver_image' => $deployment->driver && $deployment->driver->image && $deployment->driver->image->image_name 
                    ? MediaUrl::image($deployment->driver->image->image_name) 
                    : ($deployment->driver ? 'https://ui-avatars.com/api/?name=' . urlencode($deployment->driver->first_name . ' ' . $deployment->driver->last_name) : null),
                'team_members' => $teamMembers,
                'hosters' => $deployment->hosters ?? [],
                'hosters_detail' => $hostersDetail,
                'events' => $events,
                'is_responsible' => $deployment->responsible_id === (int) auth()->id(),
                'user_role' => implode(', ', $userRole),
                'user_tasks' => $userTasks,
                'total_tasks' => $deployment->tasks->count(),
                'created_at' => $deployment->created_at->format('d/m/Y H:i'),
            ]
        ]);
    }

    public function suiviOrders(Request $request)
    {
        $userId = (int)$request->query('user_id');
        $year = $request->query('year', Carbon::now()->year);
        $year = (int) $year;
        
        if (!$userId) {
            return response()->json(['error' => 'User ID required'], 400);
        }
        
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        
        $orders = Order::where('created_by', $userId)
            ->whereIn('status', ['processing', 'shipped'])
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->with(['client', 'items'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'client_name' => $order->client ? $order->client->first_name . ' ' . $order->client->last_name : null,
                    'status' => $order->status,
                    'total_with_tax' => $order->total_with_tax,
                    'created_at' => $order->created_at->format('d/m/Y'),
                    'items_count' => $order->items->count(),
                ];
            });
        
        return response()->json([
            'success' => true,
            'year' => $year,
            'total_orders' => $orders->count(),
            'orders' => $orders,
        ]);
    }

    public function orderDetails($id)
    {
        $order = Order::with(['client.city', 'client.image', 'items.product', 'items.features', 'createdBy.image', 'payments'])
            ->findOrFail($id);
        
        $items = $order->items->map(function($item) {
            $features = $item->features->map(function($feature) {
                return [
                    'name' => $feature->name,
                    'extra_price' => $feature->pivot->extra_price ?? 0,
                ];
            });
            
            return [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
                'features' => $features,
            ];
        });
        
        return response()->json([
            'success' => true,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'client_name' => $order->client ? $order->client->first_name . ' ' . $order->client->last_name : null,
                'client_phone' => $order->client ? $order->client->phone_number : null,
                'client_city' => $order->client && $order->client->city ? $order->client->city->name : null,
                'client_image' => $order->client && $order->client->image && $order->client->image->image_name 
                    ? MediaUrl::image($order->client->image->image_name) 
                    : ($order->client ? 'https://ui-avatars.com/api/?name=' . urlencode($order->client->first_name . ' ' . $order->client->last_name) : null),
                'status' => $order->status,
                'total_price' => $order->total_price,
                'tax_amount' => $order->tax_amount ?? 0,
                'discount' => $order->discount ?? 0,
                'total_with_tax' => $order->total_with_tax,
                'payment_method' => $order->payment_method,
                'paid' => $order->paid ?? 0,
                'total_paid' => $order->totalPaid(),
                'remaining_amount' => $order->remainingAmount(),
                'is_fully_paid' => $order->isFullyPaid(),
                'notes' => $order->notes,
                'created_at' => $order->created_at->format('d/m/Y H:i'),
                'created_by_name' => $order->createdBy ? $order->createdBy->first_name . ' ' . $order->createdBy->last_name : null,
                'items' => $items,
                'items_count' => $items->count(),
            ]
        ]);
    }

    public function getRankings(Request $request)
    {
        $userId = $request->query('user_id');
        $year = $request->query('year');
        $metric = $request->query('metric'); // e.g., 'total_tasks', 'participation_percentage', etc.
        $type = $request->query('type', 'tasks'); // 'tasks' or 'orders'
        $taskTypeId = $request->query('task_type_id'); // For _by_type metrics
        
        if (!$userId || !$year || !$metric) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }
        
        // Check if this is a by_type metric
        $isByType = str_ends_with($metric, '_by_type');
        $baseMetric = $isByType ? str_replace('_by_type', '', $metric) : $metric;
        
        if ($isByType && !$taskTypeId) {
            return response()->json(['error' => 'task_type_id required for by_type metrics'], 400);
        }
        
        // Reuse the same logic from suiviStats to calculate metrics for all users
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $daysInYear = $yearStart->diffInDays($yearEnd) + 1;
        
        $allUsers = User::with('image')->where('is_blocked', false)->get();
        $userMetrics = [];
        
        foreach ($allUsers as $user) {
            $uid = $user->id;
            
            if ($type === 'tasks') {
                if ($isByType) {
                    // Calculate metrics for specific task type
                    // Get the task type name from the ID
                    $taskType = \App\Models\TaskType::find($taskTypeId);
                    if (!$taskType) {
                        continue; // Skip if task type not found
                    }
                    $taskTypeName = $taskType->name;
                    
                    $baseTaskQuery = Task::where('task_type', $taskTypeName);
                    
                    // Get completed task IDs for this user and type
                    $mainTechTaskIds = (clone $baseTaskQuery)
                        ->where('technician_id', $uid)
                        ->where('status', 'terminée')
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->pluck('id')->toArray();
                    
                    $helpingTaskIds = (clone $baseTaskQuery)
                        ->where('status', 'terminée')
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->get()
                        ->filter(function($task) use ($uid) {
                            $helpingIds = $task->helping_user_ids ?? [];
                            return is_array($helpingIds) && in_array($uid, $helpingIds);
                        })
                        ->pluck('id')
                        ->toArray();
                    
                    $completedTaskIdsYear = array_unique(array_merge($mainTechTaskIds, $helpingTaskIds));
                    $totalTasksYear = count($completedTaskIdsYear);
                    
                    // Total Payment (only main technician tasks)
                    $totalPayment = (clone $baseTaskQuery)
                        ->where('technician_id', $uid)
                        ->where('is_paid', true)
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->sum('amount_paid') ?? 0;
                    
                    // Payment Rate (only main technician tasks)
                    $mainTechTasksCount = (clone $baseTaskQuery)
                        ->where('technician_id', $uid)
                        ->where('status', 'terminée')
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->count();
                    $paidMainTechTasksCount = (clone $baseTaskQuery)
                        ->where('technician_id', $uid)
                        ->where('is_paid', true)
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->count();
                    $paymentRate = $mainTechTasksCount > 0
                        ? round(($paidMainTechTasksCount / $mainTechTasksCount) * 100, 2)
                        : 0;
                    
                    // Tasks per Month
                    $tasksPerMonth = round($totalTasksYear / 12, 2);
                    
                    // Average Revenue per Month
                    $avgRevenuePerMonth = round($totalPayment / 12, 2);
                    
                    $userMetrics[] = [
                        'user_id' => $uid,
                        'user_name' => $user->name,
                        'user_image' => $user->image?->path ?? null,
                        'total_tasks' => $totalTasksYear,
                        'tasks_per_month' => $tasksPerMonth,
                        'total_payment' => round($totalPayment, 2),
                        'payment_rate' => $paymentRate,
                        'paid_tasks_count' => $paidMainTechTasksCount,
                        'main_tech_tasks_count' => $mainTechTasksCount,
                        'avg_revenue_per_month' => round($avgRevenuePerMonth, 2),
                    ];
                } else {
                    // Original logic for all tasks (not filtered by type)
                // Get completed task IDs for this user in the year
                $userCompletedTaskIds = array_unique(array_merge(
                    Task::where('technician_id', $uid)
                        ->where('status', 'terminée')
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->pluck('id')->toArray(),
                    Task::whereJsonContains('helping_user_ids', (int)$uid)
                        ->where('status', 'terminée')
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->pluck('id')->toArray()
                ));
                
                $userTotalTasks = count($userCompletedTaskIds);
                $userFinishedTasks = $userCompletedTaskIds;
                $userFinishedCount = count($userFinishedTasks);
                $userTasksPerMonth = round($userTotalTasks / 12, 2);
                
                // Calculate total payment and payment rate (only from main technician tasks)
                $userTotalPayment = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->where('is_paid', true)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->sum('amount_paid') ?? 0;
                
                $userMainTechTasksYear = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->count();
                $userPaidTasksCount = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->where('is_paid', true)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->count();
                $userPaymentRate = $userMainTechTasksYear > 0 
                    ? round(($userPaidTasksCount / $userMainTechTasksYear) * 100, 2) 
                    : 0;
                
                // Revenue (same as total payment)
                $userRevenue = $userTotalPayment;
                $userAvgRevenuePerMonth = round($userRevenue / 12, 2);
                
                // Performance metrics
                $userFinishedTaskIds = $userFinishedTasks;
                $userTotalVisits = TaskEvent::whereIn('task_id', $userFinishedTaskIds)
                    ->where('user_id', $uid)
                    ->where('event_type', 'start_visit')
                    ->count();
                $userAvgVisitsPerTask = $userFinishedCount > 0 ? round($userTotalVisits / $userFinishedCount, 2) : 0;
                
                // Average time per visit
                $userVisitDurations = [];
                $userVisitEvents = TaskEvent::whereIn('task_id', $userFinishedTaskIds)
                    ->where('user_id', $uid)
                    ->whereIn('event_type', ['start_visit', 'finish_visit'])
                    ->orderBy('event_time', 'asc')
                    ->get();
                
                $userStartVisitTime = null;
                foreach ($userVisitEvents as $event) {
                    if ($event->event_type === 'start_visit') {
                        $userStartVisitTime = Carbon::parse($event->event_time);
                    } elseif ($event->event_type === 'finish_visit' && $userStartVisitTime) {
                        $userFinishVisitTime = Carbon::parse($event->event_time);
                        $userDurationMinutes = $userStartVisitTime->diffInMinutes($userFinishVisitTime);
                        if ($userDurationMinutes > 0) {
                            $userVisitDurations[] = $userDurationMinutes;
                        }
                        $userStartVisitTime = null;
                    }
                }
                $userAvgTimePerVisit = count($userVisitDurations) > 0 
                    ? round(array_sum($userVisitDurations) / count($userVisitDurations), 2) 
                    : 0;
                
                // Get all task IDs for termination percentage
                $userAllTaskIds = array_unique(array_merge(
                    Task::where('technician_id', $uid)
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->pluck('id')->toArray(),
                    Task::whereJsonContains('helping_user_ids', (int)$uid)
                        ->whereBetween('task_date', [$yearStart, $yearEnd])
                        ->pluck('id')->toArray()
                ));
                $userAllTasksCount = count($userAllTaskIds);
                $userTerminationPercentage = $userAllTasksCount > 0 
                    ? round(($userFinishedCount / $userAllTasksCount) * 100, 2) 
                    : 0;
                
                // Participation percentage
                if (count($userFinishedTaskIds) > 0) {
                    $chunkSize = 1000;
                    $participatedTaskIds = [];
                    $chunks = array_chunk($userFinishedTaskIds, $chunkSize);
                    
                    foreach ($chunks as $chunk) {
                        $chunkTaskIds = TaskEvent::whereIn('task_id', $chunk)
                            ->where('user_id', $uid)
                            ->where('event_type', 'start_visit')
                            ->selectRaw('DISTINCT task_id')
                            ->pluck('task_id')
                            ->toArray();
                        $participatedTaskIds = array_merge($participatedTaskIds, $chunkTaskIds);
                    }
                    $userParticipatedCount = count(array_unique($participatedTaskIds));
                } else {
                    $userParticipatedCount = 0;
                }
                $userParticipationPercentage = $userFinishedCount > 0 
                    ? round(($userParticipatedCount / $userFinishedCount) * 100, 2) 
                    : 0;
                
                $userMetrics[] = [
                    'user_id' => $uid,
                    'user_name' => $user->first_name . ' ' . $user->last_name,
                    'user_image' => $user->image && $user->image->image_name ? MediaUrl::image($user->image->image_name) : null,
                    'total_tasks' => $userTotalTasks,
                    'tasks_per_month' => $userTasksPerMonth,
                    'total_payment' => round($userTotalPayment, 2),
                    'payment_rate' => $userPaymentRate,
                    'paid_tasks_count' => $userPaidTasksCount,
                    'main_tech_tasks_count' => $userMainTechTasksYear,
                    'total_revenue' => round($userRevenue, 2),
                    'avg_revenue_per_month' => $userAvgRevenuePerMonth,
                    'participation_percentage' => $userParticipationPercentage,
                    'participated_tasks_count' => $userParticipatedCount,
                    'participation_finished_tasks_count' => $userFinishedCount,
                    'avg_visits_per_task' => $userAvgVisitsPerTask,
                    'avg_time_per_visit' => $userAvgTimePerVisit,
                    'termination_percentage' => $userTerminationPercentage,
                    'finished_tasks_count' => $userFinishedCount,
                    'all_tasks_count' => $userAllTasksCount,
                ];
                }
            } elseif ($type === 'orders') {
                // Orders metrics
                $userTotalOrders = Order::where('created_by', $uid)
                    ->whereBetween('created_at', [$yearStart, $yearEnd])
                    ->count();
                
                $userRevenue = Order::where('created_by', $uid)
                    ->whereBetween('created_at', [$yearStart, $yearEnd])
                    ->sum('total_with_tax') ?? 0;
                
                $userOrdersPerMonth = round($userTotalOrders / 12, 2);
                $userAvgRevenuePerMonth = round($userRevenue / 12, 2);
                
                $userImage = null;
                if ($user->image && isset($user->image->image_name)) {
                    $userImage = MediaUrl::image($user->image->image_name);
                } elseif ($user->image_id) {
                    $image = \App\Models\Image::find($user->image_id);
                    if ($image && $image->image_name) {
                        $userImage = MediaUrl::image($image->image_name);
                    }
                }
                
                $userMetrics[] = [
                    'user_id' => $uid,
                    'user_name' => $user->first_name . ' ' . $user->last_name,
                    'user_image' => $userImage,
                    'total_orders' => $userTotalOrders,
                    'orders_per_month' => $userOrdersPerMonth,
                    'total_revenue' => round($userRevenue, 2),
                    'avg_revenue_per_month' => $userAvgRevenuePerMonth,
                ];
            } elseif ($type === 'deployments') {
                $depIds = array_unique(array_merge(
                    Deployment::where('responsible_id', $uid)->whereBetween('deployment_date', [$yearStart, $yearEnd])->pluck('id')->toArray(),
                    Deployment::where('driver_id', $uid)->whereBetween('deployment_date', [$yearStart, $yearEnd])->pluck('id')->toArray(),
                    Deployment::whereJsonContains('team_member_ids', (int)$uid)->whereBetween('deployment_date', [$yearStart, $yearEnd])->pluck('id')->toArray(),
                    Deployment::whereJsonContains('hosters', (int)$uid)->whereBetween('deployment_date', [$yearStart, $yearEnd])->pluck('id')->toArray()
                ));
                $totalDep = count($depIds);
                $timeMinutes = 0;
                foreach ($depIds as $depId) {
                    $joined = DeploymentEvent::where('deployment_id', $depId)->where('user_id', $uid)->where('event_type', DeploymentEvent::TYPE_JOINED)->orderBy('event_time')->first();
                    $endEv = DeploymentEvent::where('deployment_id', $depId)->where('event_type', DeploymentEvent::TYPE_END)->orderByDesc('event_time')->first();
                    if ($joined && $endEv && $joined->event_time && $endEv->event_time) {
                        $endTime = Carbon::parse($endEv->event_time);
                        $joinTime = Carbon::parse($joined->event_time);
                        if ($endTime->gt($joinTime)) {
                            $timeMinutes += $joinTime->diffInMinutes($endTime);
                        }
                    }
                }
                $expenses = !empty($depIds) ? DeploymentExpense::whereIn('deployment_id', $depIds)->sum('amount') : 0;
                $avgExpenses = $totalDep > 0 ? round((float) $expenses / $totalDep, 2) : 0;
                $userImage = $user->image && $user->image->image_name ? MediaUrl::image($user->image->image_name) : null;
                $userMetrics[] = [
                    'user_id' => $uid,
                    'user_name' => $user->first_name . ' ' . $user->last_name,
                    'user_image' => $userImage,
                    'total_deployments' => $totalDep,
                    'total_time_on_deployment_minutes' => $timeMinutes,
                    'deployment_expenses' => round((float) $expenses, 2),
                    'average_expenses_per_deployment' => $avgExpenses,
                ];
            }
        }
        
        // Sort by the requested metric (use base metric for by_type)
        $sortMetric = $isByType ? $baseMetric : $metric;
        $ascending = false;
        $zeroLast = false;
        
        if ($sortMetric === 'avg_time_per_visit') {
            $ascending = true; // Lower is better
            $zeroLast = true;
        }
        
        usort($userMetrics, function($a, $b) use ($sortMetric, $ascending, $zeroLast) {
            $valA = (float)($a[$sortMetric] ?? 0);
            $valB = (float)($b[$sortMetric] ?? 0);
            
            if ($zeroLast) {
                if ($valA == 0 && $valB != 0) return 1;
                if ($valA != 0 && $valB == 0) return -1;
                if ($valA == 0 && $valB == 0) return 0;
            }
            
            if ($ascending) {
                return $valA <=> $valB;
            }
            return $valB <=> $valA;
        });
        
        // Calculate ranks with ties
        $rank = 1;
        $rankedUsers = [];
        for ($i = 0; $i < count($userMetrics); $i++) {
            if ($i > 0) {
                $prevValue = (float)($userMetrics[$i - 1][$sortMetric] ?? 0);
                $currValue = (float)($userMetrics[$i][$sortMetric] ?? 0);
                
                if (abs($currValue - $prevValue) > 0.0001) {
                    $rank = $i + 1;
                }
            }
            
            $userMetrics[$i]['rank'] = $rank;
            $userMetrics[$i]['value'] = $userMetrics[$i][$sortMetric];
            $rankedUsers[] = $userMetrics[$i];
        }
        
        // Get metric labels
        $metricLabels = [
            'total_tasks' => 'Total Tâches',
            'tasks_per_month' => 'Tâches par Mois',
            'total_payment' => 'Total de Paiement',
            'payment_rate' => 'Taux de Paiement',
            'total_revenue' => 'Revenus Totaux',
            'avg_revenue_per_month' => 'Revenus Moyen/Mois',
            'participation_percentage' => 'Taux de Participation',
            'avg_visits_per_task' => 'Visites Moyennes/Tâche',
            'avg_time_per_visit' => 'Temps Moyen/Visite',
            'termination_percentage' => 'Taux de Terminaison',
            'total_orders' => 'Total Commandes',
            'orders_per_month' => 'Commandes par Mois',
            // By type metrics
            'total_tasks_by_type' => 'Total Tâches',
            'tasks_per_month_by_type' => 'Tâches par Mois',
            'total_payment_by_type' => 'Total de Paiement',
            'payment_rate_by_type' => 'Taux de Paiement',
            'avg_revenue_per_month_by_type' => 'Revenus Moyen/Mois',
            // Deployments
            'total_deployments' => 'Total Déplacements',
            'total_time_on_deployment_minutes' => 'Temps sur les déplacements',
            'deployment_expenses' => 'Dépenses déplacements',
            'average_expenses_per_deployment' => 'Moyenne dépenses / déplacement',
        ];
        
        return response()->json([
            'metric' => $metric,
            'metric_label' => $metricLabels[$metric] ?? $metric,
            'year' => $year,
            'users' => $rankedUsers,
        ]);
    }
    
    public function getTasksByType(Request $request)
    {
        $userIdParam = $request->query('user_id');
        $isAllUsers = ($userIdParam === 'all');
        $userId = $isAllUsers ? null : (int)$userIdParam;
        $year = $request->query('year');
        $taskTypeId = $request->query('task_type_id');
        
        if (!$userIdParam || !$year || !$taskTypeId) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }
        
        // Get the task type name from the ID
        $taskType = \App\Models\TaskType::find($taskTypeId);
        if (!$taskType) {
            return response()->json(['error' => 'Task type not found'], 404);
        }
        $taskTypeName = $taskType->name;
        
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        
        // Use the same query logic as yearly_breakdown, but filter by task_type
        // Get unique task IDs (tasks where user is either main tech or helping user) - ONLY COMPLETED TASKS (status = 'terminée')
        $mainTechTaskIdsQuery = Task::where('status', 'terminée')
            ->where('task_type', $taskTypeName)
            ->whereBetween('task_date', [$yearStart, $yearEnd]);
        if (!$isAllUsers && $userId) {
            $mainTechTaskIdsQuery->where('technician_id', $userId);
        }
        $mainTechTaskIds = $mainTechTaskIdsQuery->pluck('id')->toArray();
        
        // Get tasks where user is a helping user - handle JSON array properly
        if ($isAllUsers) {
            $helpingTaskIds = []; // When all users, we already count all tasks as main tech
        } else {
            $helpingTaskIds = Task::where('status', 'terminée')
                ->where('task_type', $taskTypeName)
                ->whereBetween('task_date', [$yearStart, $yearEnd])
                ->get()
                ->filter(function($task) use ($userId) {
                    $helpingIds = $task->helping_user_ids ?? [];
                    return is_array($helpingIds) && in_array($userId, $helpingIds);
                })
                ->pluck('id')
                ->toArray();
        }
        
        $completedTaskIds = array_unique(array_merge($mainTechTaskIds, $helpingTaskIds));
        
        // Total Tasks - ONLY counts finished/completed tasks (status = 'terminée')
        $totalTasks = count($completedTaskIds);
        $tasksPerMonth = round($totalTasks / 12, 2);
        
        // Calculate total payment (only from tasks where user is main technician)
        // Since only main technicians receive payment, we filter by technician_id
        $totalPaymentQuery = Task::where('status', 'terminée')
            ->where('task_type', $taskTypeName)
            ->where('is_paid', true)
            ->whereBetween('task_date', [$yearStart, $yearEnd]);
        if (!$isAllUsers && $userId) {
            $totalPaymentQuery->where('technician_id', $userId);
        }
        $totalPayment = $totalPaymentQuery->sum('amount_paid') ?? 0;
        
        // Calculate payment rate (percentage of main technician tasks that are paid)
        // Only main technician tasks can be paid
        $mainTechTasksYearQuery = Task::where('status', 'terminée')
            ->where('task_type', $taskTypeName)
            ->whereBetween('task_date', [$yearStart, $yearEnd]);
        if (!$isAllUsers && $userId) {
            $mainTechTasksYearQuery->where('technician_id', $userId);
        }
        $mainTechTasksCount = $mainTechTasksYearQuery->count();
        $paidTasksCountQuery = Task::where('status', 'terminée')
            ->where('task_type', $taskTypeName)
            ->where('is_paid', true)
            ->whereBetween('task_date', [$yearStart, $yearEnd]);
        if (!$isAllUsers && $userId) {
            $paidTasksCountQuery->where('technician_id', $userId);
        }
        $paidTasksCount = $paidTasksCountQuery->count();
        $paymentRate = $mainTechTasksCount > 0 
            ? round(($paidTasksCount / $mainTechTasksCount) * 100, 2) 
            : 0;
        
        $avgRevenuePerMonth = round($totalPayment / 12, 2);
        
        // Calculate ranks for this user across all users for this task type (skip if all users)
        $ranks = null;
        if (!$isAllUsers && $userId) {
            $allUsers = User::where('is_blocked', false)->get();
            $userMetrics = [];
            
            foreach ($allUsers as $user) {
                $uid = $user->id;
                
                // Get completed task IDs for this user and type
                $userMainTechTaskIds = Task::where('technician_id', $uid)
                    ->where('status', 'terminée')
                    ->where('task_type', $taskTypeName)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->pluck('id')->toArray();
                
                $userHelpingTaskIds = Task::where('status', 'terminée')
                    ->where('task_type', $taskTypeName)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->get()
                    ->filter(function($task) use ($uid) {
                        $helpingIds = $task->helping_user_ids ?? [];
                        return is_array($helpingIds) && in_array($uid, $helpingIds);
                    })
                    ->pluck('id')
                    ->toArray();
                
                $userCompletedTaskIds = array_unique(array_merge($userMainTechTaskIds, $userHelpingTaskIds));
                $userTotalTasks = count($userCompletedTaskIds);
                
                // Total Payment (only main technician tasks)
                $userTotalPayment = Task::where('technician_id', $uid)
                    ->where('task_type', $taskTypeName)
                    ->where('is_paid', true)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->sum('amount_paid') ?? 0;
                
                // Payment Rate (only main technician tasks)
                $userMainTechTasksCount = count($userMainTechTaskIds);
                $userPaidTasksCount = Task::where('technician_id', $uid)
                    ->where('task_type', $taskTypeName)
                    ->where('is_paid', true)
                    ->whereBetween('task_date', [$yearStart, $yearEnd])
                    ->count();
                $userPaymentRate = $userMainTechTasksCount > 0
                    ? round(($userPaidTasksCount / $userMainTechTasksCount) * 100, 2)
                    : 0;
                
                $userMetrics[] = [
                    'user_id' => $uid,
                    'total_tasks' => $userTotalTasks,
                    'tasks_per_month' => round($userTotalTasks / 12, 2),
                    'total_payment' => round($userTotalPayment, 2),
                    'payment_rate' => $userPaymentRate,
                    'avg_revenue_per_month' => round($userTotalPayment / 12, 2),
                ];
            }
            
            // Calculate ranks for each metric
            $ranks = [];
            $metrics = ['total_tasks', 'tasks_per_month', 'total_payment', 'payment_rate', 'avg_revenue_per_month'];
            
            foreach ($metrics as $metric) {
                // Sort users by this metric (descending - higher is better)
                usort($userMetrics, function($a, $b) use ($metric) {
                    return $b[$metric] <=> $a[$metric];
                });
                
                // Find the rank for the current user
                $rank = 1;
                for ($i = 0; $i < count($userMetrics); $i++) {
                    if ($i > 0) {
                        $prevValue = (float)($userMetrics[$i - 1][$metric] ?? 0);
                        $currValue = (float)($userMetrics[$i][$metric] ?? 0);
                        
                        if (abs($currValue - $prevValue) > 0.0001) {
                            $rank = $i + 1;
                        }
                    }
                    
                    if ($userMetrics[$i]['user_id'] == $userId) {
                        $ranks[$metric] = $rank;
                        break;
                    }
                }
            }
        }
        
        return response()->json([
            'total_tasks' => $totalTasks,
            'tasks_per_month' => $tasksPerMonth,
            'total_payment' => round($totalPayment, 2),
            'payment_rate' => $paymentRate,
            'paid_tasks_count' => $paidTasksCount ?? 0,
            'main_tech_tasks_count' => $mainTechTasksCount ?? 0,
            'avg_revenue_per_month' => $avgRevenuePerMonth,
            'ranks' => $ranks,
        ]);
    }

}
