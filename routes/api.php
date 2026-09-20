<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingResolutionController;
use App\Http\Controllers\Api\V1\OrgPositionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\TaskCommentController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskTagController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UserController;
use App\Support\Permissions;
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

    Route::middleware(['auth:sanctum', 'last.activity'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('active')->group(function (): void {
            Route::get('me', [ProfileController::class, 'show']);
            Route::post('uploads/images', [UploadController::class, 'image']);
            Route::post('uploads/files', [UploadController::class, 'file']);
            Route::delete('uploads/files/{file}', [UploadController::class, 'destroyFile'])->whereNumber('file');

            Route::get('meetings', [MeetingController::class, 'index'])->middleware('permission:'.Permissions::MEETINGS_VIEW);
            Route::post('meetings', [MeetingController::class, 'store'])->middleware('permission:'.Permissions::MEETINGS_CREATE);
            Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->middleware('permission:'.Permissions::MEETINGS_VIEW)->whereNumber('meeting');
            Route::patch('meetings/{meeting}', [MeetingController::class, 'update'])->middleware('permission:'.Permissions::MEETINGS_UPDATE)->whereNumber('meeting');
            Route::delete('meetings/{meeting}', [MeetingController::class, 'destroy'])->middleware('permission:'.Permissions::MEETINGS_DELETE)->whereNumber('meeting');
            Route::post('meetings/{meeting}/submit', [MeetingController::class, 'submit'])->middleware('permission:'.Permissions::MEETINGS_UPDATE)->whereNumber('meeting');
            Route::post('meetings/{meeting}/complete', [MeetingController::class, 'complete'])->middleware('permission:'.Permissions::MEETINGS_COMPLETE)->whereNumber('meeting');
            Route::post('meetings/{meeting}/resolutions', [MeetingResolutionController::class, 'store'])->middleware('permission:'.Permissions::MEETINGS_MANAGE_RESOLUTIONS)->whereNumber('meeting');
            Route::patch('meetings/{meeting}/resolutions/{resolution}', [MeetingResolutionController::class, 'update'])->middleware('permission:'.Permissions::MEETINGS_MANAGE_RESOLUTIONS)->whereNumber(['meeting', 'resolution']);
            Route::delete('meetings/{meeting}/resolutions/{resolution}', [MeetingResolutionController::class, 'destroy'])->middleware('permission:'.Permissions::MEETINGS_MANAGE_RESOLUTIONS)->whereNumber(['meeting', 'resolution']);
            Route::post('meetings/{meeting}/resolutions/{resolution}/create-task', [MeetingResolutionController::class, 'createTask'])->middleware(['permission:'.Permissions::MEETINGS_MANAGE_RESOLUTIONS, 'permission:'.Permissions::TASKS_CREATE])->whereNumber(['meeting', 'resolution']);

            Route::get('tasks', [TaskController::class, 'index'])->middleware('permission:'.Permissions::TASKS_VIEW);
            Route::post('tasks', [TaskController::class, 'store'])->middleware('permission:'.Permissions::TASKS_CREATE);
            Route::get('tasks/eligible-users', [TaskController::class, 'eligibleUsers']);
            Route::get('task-tags', [TaskTagController::class, 'index'])->middleware('permission:'.Permissions::TASKS_VIEW);
            Route::get('tasks/{task}', [TaskController::class, 'show'])->middleware('permission:'.Permissions::TASKS_VIEW);
            Route::patch('tasks/{task}', [TaskController::class, 'update'])->middleware('permission:'.Permissions::TASKS_UPDATE);
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->middleware('permission:'.Permissions::TASKS_DELETE);
            Route::post('tasks/{task}/approve', [TaskController::class, 'approve'])->middleware('permission:'.Permissions::TASKS_APPROVE);
            Route::post('tasks/{task}/reject', [TaskController::class, 'reject'])->middleware('permission:'.Permissions::TASKS_REJECT);
            Route::post('tasks/{task}/request-revision', [TaskController::class, 'requestRevision'])->middleware('permission:'.Permissions::TASKS_REJECT);
            Route::post('tasks/{task}/status', [TaskController::class, 'changeStatus'])->middleware('permission:'.Permissions::TASKS_UPDATE_STATUS);
            Route::post('tasks/{task}/progress', [TaskController::class, 'updateProgress'])->middleware('permission:'.Permissions::TASKS_UPDATE_PROGRESS);
            Route::get('tasks/{task}/comments', [TaskCommentController::class, 'index']);
            Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store']);
            Route::patch('tasks/{task}/comments/{comment}', [TaskCommentController::class, 'update'])->whereNumber(['task', 'comment']);
            Route::delete('tasks/{task}/comments/{comment}', [TaskCommentController::class, 'destroy'])->whereNumber(['task', 'comment']);
            Route::put('tasks/{task}/comments/{comment}/reaction', [TaskCommentController::class, 'reaction'])->whereNumber(['task', 'comment']);

            Route::get('org-positions/available-users', [OrgPositionController::class, 'availableUsers'])->middleware('permission:'.Permissions::ORG_POSITIONS_VIEW);
            Route::get('org-positions', [OrgPositionController::class, 'index'])->middleware('permission:'.Permissions::ORG_POSITIONS_VIEW);
            Route::post('org-positions', [OrgPositionController::class, 'store'])->middleware('permission:'.Permissions::ORG_POSITIONS_CREATE);
            Route::get('org-positions/{org_position}', [OrgPositionController::class, 'show'])->middleware('permission:'.Permissions::ORG_POSITIONS_VIEW)->whereNumber('org_position');
            Route::match(['put', 'patch'], 'org-positions/{org_position}', [OrgPositionController::class, 'update'])->middleware('permission:'.Permissions::ORG_POSITIONS_UPDATE)->whereNumber('org_position');
            Route::delete('org-positions/{org_position}', [OrgPositionController::class, 'destroy'])->middleware('permission:'.Permissions::ORG_POSITIONS_DELETE)->whereNumber('org_position');

            Route::get('users', [UserController::class, 'index'])->middleware('permission:'.Permissions::USERS_VIEW);
            Route::post('users', [UserController::class, 'store'])->middleware('permission:'.Permissions::USERS_CREATE);
            Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:'.Permissions::USERS_VIEW);
            Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->middleware('permission:'.Permissions::USERS_UPDATE);
            Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:'.Permissions::USERS_DELETE);
            Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:'.Permissions::USERS_ACTIVATE);
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('permission:'.Permissions::USERS_ACTIVATE);
            Route::post('users/{user}/send-invite', [UserController::class, 'sendInvite'])->middleware('permission:'.Permissions::USERS_RESET_PASSWORD);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:'.Permissions::USERS_RESET_PASSWORD);
        });
    });
});
