<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RoleRelationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRelationExceptionController;
use App\Http\Controllers\Api\V1\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/identify', [AuthController::class, 'identify']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/otp/send', [AuthController::class, 'sendOtp']);
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp']);
    Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('auth/password/reset', [AuthController::class, 'resetPassword']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [ProfileController::class, 'show']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::apiResource('users', UserController::class);
        Route::post('users/{user}/activate', [UserController::class, 'activate']);
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('users/{user}/send-invite', [UserController::class, 'sendInvite']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::apiResource('roles', RoleController::class);
        Route::post('roles/{role}/activate', [RoleController::class, 'activate']);
        Route::post('roles/{role}/deactivate', [RoleController::class, 'deactivate']);
        Route::get('users/{user}/roles', [UserRoleController::class, 'index']);
        Route::post('users/{user}/roles', [UserRoleController::class, 'store']);
        Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'destroy']);
        Route::post('users/{user}/roles/{role}/activate', [UserRoleController::class, 'activate']);
        Route::post('users/{user}/roles/{role}/deactivate', [UserRoleController::class, 'deactivate']);
        Route::apiResource('role-relations', RoleRelationController::class);
        Route::apiResource('user-relation-exceptions',UserRelationExceptionController::class);
    });
});
