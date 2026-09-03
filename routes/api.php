<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:registration');
    Route::post('auth/identify', [AuthController::class, 'identify'])->middleware('throttle:login');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
    Route::post('auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:otp-verify');
    Route::post('auth/password/set', [AuthController::class, 'setPassword'])->middleware('throttle:otp-verify');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('active')->group(function (): void {
            Route::get('me', [ProfileController::class, 'show']);

            Route::get('tasks', [TaskController::class, 'index']);
            Route::post('tasks', [TaskController::class, 'store']);
            Route::get('tasks/eligible-users', [TaskController::class, 'eligibleUsers']);
            Route::get('tasks/{task}', [TaskController::class, 'show']);
            Route::patch('tasks/{task}', [TaskController::class, 'update']);
            Route::post('tasks/{task}/approve', [TaskController::class, 'approve']);
            Route::post('tasks/{task}/reject', [TaskController::class, 'reject']);
            Route::post('tasks/{task}/request-revision', [TaskController::class, 'requestRevision']);
            Route::post('tasks/{task}/status', [TaskController::class, 'changeStatus']);
            Route::post('tasks/{task}/progress', [TaskController::class, 'updateProgress']);

            Route::get('roles', [RoleController::class, 'index']);
            Route::get('roles/{role}', [RoleController::class, 'show'])->whereNumber('role');

            Route::middleware('admin')->group(function (): void {
                Route::get('roles/available-users', [RoleController::class, 'availableUsers']);
                Route::post('roles', [RoleController::class, 'store']);
                Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
                Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
                Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
                Route::apiResource('users', UserController::class);
                Route::post('users/{user}/activate', [UserController::class, 'activate']);
                Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
                Route::post('users/{user}/send-invite', [UserController::class, 'sendInvite']);
                Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
            });
        });
    });
});
