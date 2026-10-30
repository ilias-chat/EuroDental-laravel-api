<?php

use App\Http\Controllers\Api\V1\Crm\AuthController;
use App\Http\Controllers\Api\V1\Crm\ClientController;
use App\Http\Controllers\Api\V1\Crm\ClientDuplicateController;
use App\Http\Controllers\Api\V1\Crm\DashboardController;
use App\Http\Controllers\Api\V1\Crm\LeaveRequestController;
use App\Http\Controllers\Api\V1\Crm\NotificationController;
use App\Http\Controllers\Api\V1\Crm\ProductController as CrmProductController;
use App\Http\Controllers\Api\V1\Crm\ProfileController;
use App\Http\Controllers\Api\V1\Crm\ServiceController;
use App\Http\Controllers\Api\V1\Crm\TaskCalendarController;
use App\Http\Controllers\Api\V1\Crm\TaskController;
use App\Http\Controllers\Api\V1\Crm\TaskCommentController;
use App\Http\Controllers\Api\V1\Crm\TaskFollowController;
use App\Http\Controllers\Api\V1\Crm\TrackingController;
use App\Http\Controllers\Api\V1\Crm\UserController;
use App\Http\Controllers\Api\V1\Crm\UserFlagController;
use App\Http\Controllers\Api\V1\Crm\UserFlagTitleController;
use App\Http\Controllers\Api\V1\Crm\TaskTypeController;
use App\Http\Controllers\Api\V1\Webshop\ClientAuthController;
use App\Http\Controllers\Api\V1\Webshop\ProductController;
use App\Http\Middleware\CheckPermission;
use Illuminate\Support\Facades\Route;

/* CRM and webshop API only. */
Route::prefix('v1')->group(function () {
    Route::get('notifications/vapid-public-key', [NotificationController::class, 'vapidPublicKey']);

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    Route::prefix('shop')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('login', [ClientAuthController::class, 'login']);
            Route::post('register', [ClientAuthController::class, 'register']);
            Route::middleware('auth:sanctum')->group(function () {
                Route::get('me', [ClientAuthController::class, 'me']);
                Route::post('logout', [ClientAuthController::class, 'logout']);
            });
        });
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/search', [ProductController::class, 'search']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':clients_read'])->group(function () {
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/meta', [ClientController::class, 'meta']);
        Route::get('clients/action-required', [ClientController::class, 'actionRequired']);
        Route::get('clients/duplicates', [ClientDuplicateController::class, 'index']);
        Route::get('clients/replacements', [ClientController::class, 'replacements']);
        Route::get('clients/{clientId}', [ClientController::class, 'show'])->whereNumber('clientId');
        Route::get('clients/{clientId}/stats', [ClientController::class, 'clientStats'])->whereNumber('clientId');
        Route::get('clients/{clientId}/tasks', [ClientController::class, 'tasks'])->whereNumber('clientId');
        Route::get('clients/{clientId}/orders', [ClientController::class, 'orders'])->whereNumber('clientId');
        Route::get('clients/{clientId}/warranty-products', [ClientController::class, 'products'])->whereNumber('clientId');
        Route::get('clients/{clientId}/payments', [ClientController::class, 'payments'])->whereNumber('clientId');
    });
    Route::middleware(['auth:sanctum', CheckPermission::class . ':clients_stats'])->group(function () {
        Route::get('clients/stats', [ClientController::class, 'stats']);
    });
    Route::middleware(['auth:sanctum', CheckPermission::class . ':clients_write'])->group(function () {
        Route::post('clients/duplicates/scan', [ClientDuplicateController::class, 'scan']);
        Route::post('clients/duplicates/check', [ClientDuplicateController::class, 'check']);
        Route::patch('clients/duplicates/{duplicate}', [ClientDuplicateController::class, 'review']);
        Route::get('clients/duplicates/{duplicate}/preview', [ClientDuplicateController::class, 'preview']);
        Route::post('clients/duplicates/{duplicate}/merge', [ClientDuplicateController::class, 'merge']);
        Route::get('clients/duplicates/{duplicate}/undo-preview', [ClientDuplicateController::class, 'undoPreview']);
        Route::post('clients/duplicates/{duplicate}/undo', [ClientDuplicateController::class, 'undo']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::post('clients/{client}', [ClientController::class, 'update'])->whereNumber('client');
        Route::get('clients/{client}/dependencies', [ClientController::class, 'checkDependencies'])->whereNumber('client');
        Route::delete('clients/{client}', [ClientController::class, 'destroyWithReplacement'])->whereNumber('client');
        Route::post('clients/{client}/validate', [ClientController::class, 'validateWebshopClient'])->whereNumber('client');
        Route::post('clients/{client}/sync', [ClientController::class, 'syncWebshopClient'])->whereNumber('client');
        Route::post('clients/{client}/ignore', [ClientController::class, 'ignoreWebshopClient'])->whereNumber('client');
        Route::post('client-cities', [ClientController::class, 'storeCity']);
        Route::put('client-cities/{city}', [ClientController::class, 'updateCity']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':dashboard_read'])->group(function () {
        Route::get('dashboard/revenue', [DashboardController::class, 'revenue']);
        Route::get('dashboard/payments', [DashboardController::class, 'payments']);
        Route::get('dashboard/products', [DashboardController::class, 'products']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':products_read'])->group(function () {
        Route::get('products', [CrmProductController::class, 'index']);
        Route::get('products/meta', [CrmProductController::class, 'meta']);
        Route::get('products/stats', [CrmProductController::class, 'stats']);
        Route::get('products/stock-shortages', [CrmProductController::class, 'stockShortages']);
        Route::get('products/{product:id}/orders', [CrmProductController::class, 'productOrders'])->whereNumber('product');
        Route::get('products/{product:id}', [CrmProductController::class, 'show'])->whereNumber('product');
    });
    Route::middleware(['auth:sanctum', CheckPermission::class . ':products_write'])->group(function () {
        Route::post('products', [CrmProductController::class, 'store']);
        Route::post('products/{product:id}', [CrmProductController::class, 'update'])->whereNumber('product');
        Route::patch('products/{product:id}/stock', [CrmProductController::class, 'updateStock'])->whereNumber('product');
        Route::delete('products/{product:id}', [CrmProductController::class, 'destroy'])->whereNumber('product');
        Route::post('product-categories', [CrmProductController::class, 'storeCategory']);
        Route::put('product-categories/{category}', [CrmProductController::class, 'updateCategory']);
        Route::delete('product-categories/{category}', [CrmProductController::class, 'destroyCategory']);
        Route::post('product-sub-categories', [CrmProductController::class, 'storeSubCategory']);
        Route::put('product-sub-categories/{subCategory}', [CrmProductController::class, 'updateSubCategory']);
        Route::delete('product-sub-categories/{subCategory}', [CrmProductController::class, 'destroySubCategory']);
        Route::post('product-brands', [CrmProductController::class, 'storeBrand']);
        Route::put('product-brands/{brand}', [CrmProductController::class, 'updateBrand']);
        Route::delete('product-brands/{brand}', [CrmProductController::class, 'destroyBrand']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':users_read'])->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/meta', [UserController::class, 'meta']);
        Route::get('profiles', [ProfileController::class, 'index']);
        Route::get('profiles/{profile}/permissions', [ProfileController::class, 'permissions']);
    });
    Route::middleware(['auth:sanctum', CheckPermission::class . ':users_write'])->group(function () {
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::patch('users/{user}/blocked', [UserController::class, 'setBlocked']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('users/{user}/test-notification', [UserController::class, 'sendTestNotification']);
        Route::post('profiles', [ProfileController::class, 'store']);
        Route::put('profiles/{profile}', [ProfileController::class, 'update']);
        Route::delete('profiles/{profile}', [ProfileController::class, 'destroy']);
        Route::put('profiles/{profile}/permissions', [ProfileController::class, 'syncPermissions']);
        Route::post('profiles/reorder', [ProfileController::class, 'reorder']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':user_flags'])->group(function () {
        Route::get('users/{user}/flag-events', [UserFlagController::class, 'index']);
        Route::post('users/{user}/flag-events', [UserFlagController::class, 'store']);
        Route::put('users/{user}/flag-events/{flag}', [UserFlagController::class, 'update']);
        Route::patch('users/{user}/flag-events/{flag}', [UserFlagController::class, 'retire']);
        Route::delete('users/{user}/flag-events/{flag}', [UserFlagController::class, 'destroy']);
        Route::get('flag-titles', [UserFlagTitleController::class, 'index']);
        Route::post('flag-titles', [UserFlagTitleController::class, 'store']);
        Route::put('flag-titles/{flagTitle}', [UserFlagTitleController::class, 'update']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':manage_vacations'])->group(function () {
        Route::get('leave-requests', [LeaveRequestController::class, 'index']);
        Route::get('leave-requests/unavailable-users', [LeaveRequestController::class, 'unavailableUsers']);
        Route::get('leave-requests/history', [LeaveRequestController::class, 'history']);
        Route::get('leave-requests/user-stats/{userId}', [LeaveRequestController::class, 'userStats'])->whereNumber('userId');
        Route::get('leave-requests/{id}/details', [LeaveRequestController::class, 'details'])->whereNumber('id');
        Route::post('leave-requests/{id}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('id');
        Route::post('leave-requests/{id}/deny', [LeaveRequestController::class, 'deny'])->whereNumber('id');
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':tasks_read'])->group(function () {
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{id}', [TaskController::class, 'show'])->whereNumber('id');
        Route::get('tasks/{task}/comments', [TaskCommentController::class, 'index'])->whereNumber('task');
        Route::get('tasks/{task}/follow', [TaskFollowController::class, 'show'])->whereNumber('task');
        Route::post('tasks/{task}/follow', [TaskFollowController::class, 'store'])->whereNumber('task');
        Route::delete('tasks/{task}/follow', [TaskFollowController::class, 'destroy'])->whereNumber('task');
        Route::post('tasks/{task}/comments/read', [TaskCommentController::class, 'markRead'])->whereNumber('task');
        Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store'])->whereNumber('task')->middleware('throttle:30,1');
        Route::get('tasks/calendar/month', [TaskCalendarController::class, 'month']);
        Route::get('tasks/delivered-payments', [TaskCalendarController::class, 'deliveredPayments']);
        Route::get('tasks/types', [TaskTypeController::class, 'index']);
        Route::get('services', [ServiceController::class, 'getAll']);
    });

    Route::middleware(['auth:sanctum', CheckPermission::class . ':tasks_tracking'])->group(function () {
        Route::get('tracking/month', [TrackingController::class, 'suiviMonthTasks']);
        Route::get('tracking/stats', [TrackingController::class, 'suiviStats']);
        Route::get('tracking/profile', [TrackingController::class, 'suiviProfile']);
        Route::get('tracking/deployments', [TrackingController::class, 'suiviDeployments']);
        Route::get('tracking/deployments/{id}', [TrackingController::class, 'deploymentDetails'])->whereNumber('id');
        Route::get('tracking/orders', [TrackingController::class, 'suiviOrders']);
        Route::get('tracking/orders/{id}', [TrackingController::class, 'orderDetails'])->whereNumber('id');
        Route::get('tracking/rankings', [TrackingController::class, 'getRankings']);
        Route::get('tracking/tasks-by-type', [TrackingController::class, 'getTasksByType']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('notifications/push-subscriptions', [NotificationController::class, 'storePushSubscription']);
        Route::delete('notifications/push-subscriptions', [NotificationController::class, 'destroyPushSubscription']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereNumber('id');
        Route::put('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereNumber('id');
        Route::put('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id');
    });
});
