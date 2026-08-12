<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Media\MediaUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    public function index()
    {
        $requests = LeaveRequest::with(['user.image', 'reviewer'])
            ->where('status', 'waiting')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (LeaveRequest $leaveRequest) => $this->map($leaveRequest));

        return response()->json(['success' => true, 'requests' => $requests]);
    }

    public function unavailableUsers(Request $request)
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $requests = LeaveRequest::with(['user.image', 'reviewer'])
            ->active($data['start_date'], $data['end_date'])
            ->get()
            ->groupBy('user_id')
            ->map(function ($userRequests) {
                $user = $userRequests->first()->user;

                return [
                    'user_id' => $user->id,
                    'user_name' => trim($user->first_name.' '.$user->last_name),
                    'user_image' => $user->image ? MediaUrl::image($user->image->image_name) : null,
                    'requests' => $userRequests->map(fn (LeaveRequest $leaveRequest) => $this->map($leaveRequest))->values(),
                ];
            })
            ->values();

        return response()->json(['success' => true, 'users' => $requests]);
    }

    public function userStats(int $userId, Request $request)
    {
        $year = (int) $request->input('year', now()->year);
        $requests = LeaveRequest::where('user_id', $userId)
            ->whereIn('status', ['accepted', 'on_leave', 'completed'])
            ->whereYear('start_date', $year)
            ->orderBy('start_date', 'desc')
            ->get();

        $periods = $requests->map(function (LeaveRequest $leaveRequest) {
            $days = $leaveRequest->start_date->diffInDays($leaveRequest->end_date) + 1;

            return [
                'id' => $leaveRequest->id,
                'start_date' => $leaveRequest->start_date?->format('Y-m-d'),
                'end_date' => $leaveRequest->end_date?->format('Y-m-d'),
                'days' => $days,
                'leave_type' => $leaveRequest->leave_type,
            ];
        });

        return response()->json([
            'success' => true,
            'year' => $year,
            'total_days' => $periods->sum('days'),
            'total_periods' => $periods->count(),
            'periods' => $periods,
        ]);
    }

    public function details(int $id)
    {
        $leaveRequest = LeaveRequest::with(['user.image', 'reviewer'])->findOrFail($id);

        return response()->json(['success' => true, 'request' => $this->map($leaveRequest, true)]);
    }

    public function approve(int $id)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);

        if ($leaveRequest->status !== 'waiting') {
            return response()->json(['success' => false, 'message' => 'Cette demande a deja ete traitee.'], 422);
        }

        $leaveRequest->update([
            'status' => 'accepted',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Demande de conge acceptee.']);
    }

    public function deny(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $leaveRequest = LeaveRequest::findOrFail($id);

        if ($leaveRequest->status !== 'waiting') {
            return response()->json(['success' => false, 'message' => 'Cette demande a deja ete traitee.'], 422);
        }

        $leaveRequest->update([
            'status' => 'denied',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'rejection_reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Demande de conge refusee.']);
    }

    public function history(Request $request)
    {
        $query = LeaveRequest::with(['user.image', 'reviewer']);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('year')) {
            $query->whereYear('start_date', $request->year);
        }

        $requests = $query->orderBy('start_date', 'desc')
            ->get()
            ->map(fn (LeaveRequest $leaveRequest) => $this->map($leaveRequest, true));

        return response()->json(['success' => true, 'requests' => $requests]);
    }

    private function map(LeaveRequest $leaveRequest, bool $withReview = false): array
    {
        $user = $leaveRequest->user ?: User::find($leaveRequest->user_id);

        $data = [
            'id' => $leaveRequest->id,
            'user_id' => $leaveRequest->user_id,
            'user_name' => $user ? trim($user->first_name.' '.$user->last_name) : '-',
            'user_image' => $user?->image ? MediaUrl::image($user->image->image_name) : null,
            'start_date' => $leaveRequest->start_date ? Carbon::parse($leaveRequest->start_date)->format('Y-m-d') : null,
            'end_date' => $leaveRequest->end_date ? Carbon::parse($leaveRequest->end_date)->format('Y-m-d') : null,
            'leave_type' => $leaveRequest->leave_type,
            'description' => $leaveRequest->description,
            'justification_method' => $leaveRequest->justification_method,
            'created_at' => $leaveRequest->created_at?->format('Y-m-d H:i'),
        ];

        if ($withReview) {
            $data += [
                'status' => $leaveRequest->status,
                'rejection_reason' => $leaveRequest->rejection_reason,
                'reviewer_name' => $leaveRequest->reviewer ? trim($leaveRequest->reviewer->first_name.' '.$leaveRequest->reviewer->last_name) : null,
                'reviewed_at' => $leaveRequest->reviewed_at?->format('Y-m-d H:i'),
            ];
        }

        return $data;
    }
}
