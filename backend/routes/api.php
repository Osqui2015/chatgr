<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EphemeralMediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TypingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Every route below is protected by Sanctum. Authentication is cookie based:
| the session cookie is HttpOnly, Secure and SameSite=Lax, so the SPA never
| has to handle a bearer token. State changing requests must send the
| X-XSRF-TOKEN header.
|
*/

Route::prefix('auth')->group(function (): void {
    // Refreshes the CSRF token without touching the session.
    Route::get('/csrf', function (Request $request): JsonResponse {
        return response()->json([
            'token' => $request->session()->token(),
        ]);
    })->name('api.auth.csrf');

    Route::middleware('throttle:login')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('api.auth.register');
        Route::post('/login', [AuthController::class, 'login'])->name('api.auth.login');
    });

    Route::middleware('throttle:otp')->group(function (): void {
        Route::post('/verify/registration', [AuthController::class, 'verifyRegistration'])->name('api.auth.verify.registration');
        Route::post('/verify/login', [AuthController::class, 'verifyLogin'])->name('api.auth.verify.login');
        Route::post('/resend', [AuthController::class, 'resendCode'])->name('api.auth.resend');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    /*
     * Presence
     */
    Route::post('/users/me/status', [UserController::class, 'updateStatus'])->name('api.users.status');
    Route::post('/users/me/avatar', [UserController::class, 'updateAvatar'])->name('api.users.avatar.store');
    Route::delete('/users/me/avatar', [UserController::class, 'destroyAvatar'])->name('api.users.avatar.destroy');
    Route::patch('/users/me', [UserController::class, 'update'])->name('api.users.update');

    /*
     * Users, blocking and muting
     */
    Route::get('/users/search', [UserController::class, 'search'])->name('api.users.search');
    Route::get('/users/me/blocks', [UserController::class, 'blocks'])->name('api.users.blocks.index');
    Route::post('/users/{user}/block', [UserController::class, 'block'])->name('api.users.block');
    Route::delete('/users/{user}/block', [UserController::class, 'unblock'])->name('api.users.unblock');
    Route::post('/users/{user}/mute', [UserController::class, 'mute'])->name('api.users.mute');
    Route::delete('/users/{user}/mute', [UserController::class, 'unmute'])->name('api.users.unmute');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('api.users.show');

    /*
     * Conversations
     */
    Route::get('/conversations', [ConversationController::class, 'index'])->name('api.conversations.index');
    Route::get('/conversations/global', [ConversationController::class, 'global'])->name('api.conversations.global');
    Route::post('/conversations', [ConversationController::class, 'store'])->name('api.conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('api.conversations.show');

    /*
     * Messages
     */
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])->name('api.messages.index');
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->name('api.messages.store');
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'markRead'])->name('api.messages.read');
    Route::post('/conversations/{conversation}/typing', [TypingController::class, 'store'])->name('api.messages.typing');
    Route::patch('/messages/{message}', [MessageController::class, 'update'])->name('api.messages.update');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('api.messages.destroy');
    Route::post('/messages/{message}/reactions', [MessageController::class, 'toggleReaction'])->name('api.messages.reactions');

    /*
     * View-once media
     */
    Route::post('/media/ephemeral', [EphemeralMediaController::class, 'store'])
        ->middleware('throttle:upload')
        ->name('api.media.ephemeral.store');

    Route::get('/media/ephemeral/asset/{uuid}', [EphemeralMediaController::class, 'asset'])
        ->middleware('throttle:30,1')
        ->name('api.media.ephemeral.asset');

    /*
     * Reports
     */
    Route::get('/reports', [ReportController::class, 'index'])->name('api.reports.index');
    Route::post('/reports', [ReportController::class, 'store'])->name('api.reports.store');

    /*
     * Global search
     */
    Route::get('/search', [SearchController::class, 'index'])->name('api.search');
});

/*
 * The redemption of a view-once token is intentionally outside the
 * authenticated group: the token itself is the credential. It is
 * single use and short lived, and it is rate limited.
 */
Route::get('/media/ephemeral/{token}', [EphemeralMediaController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('api.media.ephemeral.show');

/*
 * Administration
 */
Route::middleware(['auth:sanctum', 'role:admin,moderator', 'throttle:moderation'])
    ->prefix('admin')
    ->name('api.admin.')
    ->group(function (): void {
        Route::get('/statistics', [AdminController::class, 'statistics'])->name('statistics');
        Route::get('/users', [AdminController::class, 'users'])->name('users.index');
        Route::patch('/users/{user}/role', [AdminController::class, 'updateRole'])->name('users.role');
        Route::post('/users/{user}/ban', [AdminController::class, 'ban'])->name('users.ban');
        Route::delete('/users/{user}/ban', [AdminController::class, 'unban'])->name('users.unban');
        Route::get('/bans', [AdminController::class, 'bans'])->name('bans.index');
        Route::get('/reports', [AdminController::class, 'reports'])->name('reports.index');
        Route::patch('/reports/{report}', [AdminController::class, 'resolveReport'])->name('reports.resolve');
        Route::delete('/messages/{message}', [AdminController::class, 'deleteMessage'])->name('messages.destroy');
        Route::get('/online', [AdminController::class, 'onlineUsers'])->name('online');
    });
