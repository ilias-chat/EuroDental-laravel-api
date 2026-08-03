<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        View::composer('layouts.admin', function ($view) {
            $permissions = [];
    
            if (Auth::check() && Auth::user()->profile) {
                $permissions = Auth::user()->profile->permissions->pluck('code')->toArray();
            }
    
            $view->with('permissionCodes', $permissions);
        });
    }
}
