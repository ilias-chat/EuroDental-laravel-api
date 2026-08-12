<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Profile;
use App\Models\User;
use App\Services\Media\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['profile.permissions', 'city', 'image', 'leaveRequests' => function ($query) {
                $query->whereIn('status', ['accepted', 'on_leave'])
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now());
            }])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('first_name', 'like', '%'.$request->search.'%')->orWhere('last_name', 'like', '%'.$request->search.'%')->orWhere('email', 'like', '%'.$request->search.'%')))
            ->when($request->filled('profile_id'), fn ($q) => $q->where('profile_id', $request->profile_id))
            ->orderBy('first_name')->orderBy('last_name')->get()->map(fn (User $user) => $this->map($user));
        return response()->json(['users' => $users]);
    }
    public function meta() { return response()->json(['profiles' => Profile::with('permissions:id,code')->orderBy('sort_order')->get()->map(fn ($p) => ['id'=>$p->id,'profile_name'=>$p->profile_name,'permissions'=>$p->permissions->pluck('code')->values()]), 'cities' => City::orderBy('name')->get(['id','name'])]); }
    public function store(Request $request) { $data=$this->validateUser($request); $password=Str::random(12); $user=User::create([...$data,'password'=>Hash::make($password)]); return response()->json(['user'=>$this->map($user->load(['profile','city','image']))],201); }
    public function update(Request $request, User $user) { $user->update($this->validateUser($request,$user)); return response()->json(['user'=>$this->map($user->fresh(['profile','city','image','leaveRequests']))]); }
    public function destroy(User $user) { abort_if($user->is(auth()->user()),422,'Vous ne pouvez pas supprimer votre propre compte.'); $user->delete(); return response()->noContent(); }
    public function setBlocked(Request $request, User $user) { $user->update(['is_blocked'=>$request->boolean('blocked')]); return response()->json(['user'=>$this->map($user->fresh(['profile','city','image','leaveRequests']))]); }
    public function resetPassword(User $user) { $password=Str::random(12); $user->update(['password'=>Hash::make($password),'requires_password_change'=>true]); Mail::to($user->email)->send(new \App\Mail\PasswordResetMail($user,$password)); return response()->json(['message'=>'Un nouveau mot de passe a été envoyé.']); }
    private function validateUser(Request $request, ?User $user=null): array { return $request->validate(['first_name'=>'required|string|max:255','last_name'=>'required|string|max:255','email'=>'required|email|unique:users,email,'.($user?->id ?? 'NULL'),'profile_id'=>'required|exists:profiles,id','city_id'=>'nullable|exists:cities,id','phone_number'=>'nullable|string|max:20','address'=>'nullable|string|max:500']); }
    private function map(User $u): array { $activeLeave=$u->relationLoaded('leaveRequests')?$u->leaveRequests->first(fn ($leave) => in_array($leave->status, ['accepted', 'on_leave'], true) && $leave->start_date <= now() && $leave->end_date >= now()):null; return ['id'=>$u->id,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->email,'phone_number'=>$u->phone_number,'address'=>$u->address,'profile_id'=>$u->profile_id,'profile_name'=>$u->profile?->profile_name,'city_id'=>$u->city_id,'city_name'=>$u->city?->name,'image_url'=>$u->image?MediaUrl::image($u->image->image_name):null,'is_blocked'=>(bool)$u->is_blocked,'has_active_leave'=>(bool)$activeLeave,'active_leave'=>$activeLeave?['start_date'=>$activeLeave->start_date?->format('Y-m-d'),'end_date'=>$activeLeave->end_date?->format('Y-m-d')]:null]; }
}
