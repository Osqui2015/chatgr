<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Ban;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Services\MessageService;
use App\Services\ModerationService;
use App\Services\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The administration panel API.
 *
 * Every action is guarded by a policy; the routes additionally require the
 * `admin` or `moderator` role.
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly ModerationService $moderation,
        private readonly MessageService $messages,
    ) {}

    /**
     * Panel statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('accessAdminPanel', $request->user());

        return response()->json([
            'data' => [
                'users' => [
                    'total' => User::query()->count(),
                    'verified' => User::query()->whereNotNull('email_verified_at')->count(),
                    'banned' => User::query()->where('is_banned', true)->count(),
                    'new_24h' => User::query()->where('created_at', '>=', Carbon::now()->subDay())->count(),
                ],
                'messages' => [
                    'total' => Message::query()->count(),
                    'last_24h' => Message::query()->where('created_at', '>=', Carbon::now()->subDay())->count(),
                    'deleted' => Message::withTrashed()->whereNotNull('deleted_at')->count(),
                ],
                'presence' => [
                    'online' => $this->presence->onlineCount(),
                ],
                'moderation' => [
                    'pending_reports' => Report::query()->where('status', 'pending')->count(),
                    'active_bans' => Ban::query()->whereNull('revoked_at')->count(),
                ],
            ],
        ]);
    }

    /**
     * List users with optional filters.
     */
    public function users(Request $request): JsonResponse
    {
        $this->authorize('accessAdminPanel', $request->user());

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', Rule::in(UserRole::values())],
            'banned' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->orderByDesc('id');

        if (! empty($validated['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['q']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('nickname', 'like', $term)->orWhere('email', 'like', $term);
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (isset($validated['banned'])) {
            $query->where('is_banned', $validated['banned']);
        }

        $users = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Change the role of a user.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $this->authorize('changeRole', $user);

        $validated = $request->validate([
            'role' => ['required', Rule::in(UserRole::values())],
        ], [
            'role.required' => 'Indica el nuevo rol.',
            'role.in' => 'El rol indicado no es válido.',
        ]);

        $user->forceFill(['role' => UserRole::from($validated['role'])])->save();

        return response()->json([
            'message' => 'Rol actualizado.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Ban a user.
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        $this->authorize('ban', $user);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $expiresAt = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;

        $this->moderation->ban($request->user(), $user, $validated['reason'] ?? null, $expiresAt);

        return response()->json([
            'message' => 'Usuario baneado.',
        ]);
    }

    /**
     * Lift a ban.
     */
    public function unban(Request $request, User $user): JsonResponse
    {
        $this->authorize('ban', $user);

        $this->moderation->unban($request->user(), $user);

        return response()->json([
            'message' => 'Ban aplicado.',
        ]);
    }

    /**
     * List the accounts with an active ban.
     */
    public function bans(Request $request): JsonResponse
    {
        $this->authorize('accessAdminPanel', $request->user());

        $bans = Ban::query()
            ->with(['user:id,nickname,email,avatar_path', 'moderator:id,nickname'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $bans,
        ]);
    }

    /**
     * List pending reports.
     */
    public function reports(Request $request): JsonResponse
    {
        $this->authorize('accessAdminPanel', $request->user());

        $status = $request->string('status')->toString();

        $query = Report::query()
            ->with(['reporter:id,nickname', 'reportedUser:id,nickname,email,avatar_path'])
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $reports = $query->limit(100)->get();

        return response()->json([
            'data' => $reports,
        ]);
    }

    /**
     * Resolve a report.
     */
    public function resolveReport(Request $request, Report $report): JsonResponse
    {
        $this->authorize('reviewReports', $request->user());

        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewing', 'resolved', 'dismissed'])],
        ]);

        $report = $this->moderation->resolveReport($request->user(), $report, $validated['status']);

        return response()->json([
            'message' => 'Denuncia actualizada.',
            'report' => $report,
        ]);
    }

    /**
     * Delete a reported message.
     */
    public function deleteMessage(Request $request, Message $message): JsonResponse
    {
        $this->authorize('accessAdminPanel', $request->user());

        $this->messages->delete($message);

        return response()->json([
            'message' => 'Mensaje eliminado.',
        ]);
    }

    /**
     * The list of currently connected users.
     */
    public function onlineUsers(Request $request): JsonResponse
    {
        $this->authorize('accessAdminPanel', $request->user());

        $ids = $this->presence->onlineUserIds();

        $users = User::query()
            ->whereIn('id', $ids)
            ->orderByDesc('last_seen_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => UserResource::collection($users),
            'meta' => [
                'count' => $users->count(),
            ],
        ]);
    }
}
