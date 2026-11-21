<?php

namespace App\Http\Controllers\Api;

use App\Enums\SexualRole;
use App\Enums\UserStatus;
use App\Events\UserPresenceChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdateStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ImageService;
use App\Services\ModerationService;
use App\Services\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Profile management, presence and user discovery.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly ImageService $images,
        private readonly PresenceService $presence,
        private readonly ModerationService $moderation,
    ) {}

    /**
     * Update the profile of the authenticated user.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nickname' => [
                'sometimes',
                'string',
                'min:3',
                'max:32',
                'regex:/^[\p{L}\p{N}._\- ]+$/u',
                'unique:users,nickname,'.$request->user()->id,
            ],
            'sexual_role' => [
                'sometimes',
                'nullable',
                Rule::in(SexualRole::values()),
            ],
        ], [
            'nickname.unique' => 'Ese nombre de usuario ya está en uso.',
            'nickname.regex' => 'El nombre solo puede contener letras, números, espacios, punto, guion y guion bajo.',
            'sexual_role.in' => 'El rol seleccionado no es válido.',
        ]);

        $user = $request->user();
        $user->fill($validated)->save();

        return response()->json([
            'message' => 'Perfil actualizado.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Upload a new avatar.
     *
     * The upload is re-encoded and stored in four square WebP variants; the
     * original file is discarded so no metadata ever reaches storage.
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $clean = $this->images->sanitize($request->file('avatar'));
        $variants = $this->images->generateAvatarVariants($clean);

        $previous = $user->avatar_path;
        $path = $this->images->storeAvatarVariants($variants, $user->id);

        $user->forceFill([
            'avatar_path' => $path,
            'avatar_mime' => 'image/webp',
            'avatar_size' => strlen($variants[128] ?? ''),
        ])->save();

        if ($previous !== null && $previous !== $path) {
            $this->images->deleteAvatarVariants($previous);
        }

        return response()->json([
            'message' => 'Avatar actualizado.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Delete the current avatar.
     */
    public function destroyAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->images->deleteAvatarVariants($user->avatar_path);

        $user->forceFill([
            'avatar_path' => null,
            'avatar_mime' => null,
            'avatar_size' => null,
        ])->save();

        return response()->json([
            'message' => 'Avatar eliminado.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Change the presence status.
     */
    public function updateStatus(UpdateStatusRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $status = UserStatus::from($request->string('status')->toString());

        $this->presence->setStatus($user, $status);

        UserPresenceChanged::dispatch(
            $user->id,
            $status->value,
            $user->last_seen_at?->toIso8601String(),
        );

        return response()->json([
            'message' => 'Estado actualizado.',
            'status' => $status->value,
        ]);
    }

    /**
     * Search users by nickname.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:32'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], [
            'q.required' => 'Escribe al menos 2 caracteres para buscar.',
            'q.min' => 'Escribe al menos 2 caracteres para buscar.',
        ]);

        $term = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 20);

        $users = User::query()
            ->where('id', '!=', $request->user()->id)
            ->where('nickname', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
            ->orderBy('nickname')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => UserResource::collection($users),
        ]);
    }

    /**
     * Show a single user's public profile.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();

        return response()->json([
            'data' => UserResource::make($user),
            'moderation' => [
                'blocked' => $viewer !== null
                    && in_array($user->id, $this->moderation->blockedUserIds($viewer), true),
                'muted' => $viewer !== null
                    && in_array($user->id, $this->moderation->mutedUserIds($viewer), true),
            ],
        ]);
    }

    /**
     * List the users the authenticated user has blocked.
     */
    public function blocks(Request $request): JsonResponse
    {
        $blocked = $request->user()->blockedUsers()->get();

        return response()->json([
            'data' => UserResource::collection($blocked),
        ]);
    }

    /**
     * Block a user.
     */
    public function block(Request $request, User $user): JsonResponse
    {
        $this->authorize('block', $user);

        $this->moderation->block($request->user(), $user, $request->input('reason'));

        return response()->json([
            'message' => 'Usuario bloqueado.',
        ]);
    }

    /**
     * Unblock a user.
     */
    public function unblock(Request $request, User $user): JsonResponse
    {
        $this->moderation->unblock($request->user(), $user);

        return response()->json([
            'message' => 'Usuario desbloqueado.',
        ]);
    }

    /**
     * Mute a user so their messages are hidden for the authenticated user.
     */
    public function mute(Request $request, User $user): JsonResponse
    {
        $this->authorize('block', $user);

        $this->moderation->mute($request->user(), $user);

        return response()->json([
            'message' => 'Usuario silenciado.',
        ]);
    }

    /**
     * Unmute a user.
     */
    public function unmute(Request $request, User $user): JsonResponse
    {
        $this->moderation->unmute($request->user(), $user);

        return response()->json([
            'message' => 'Usuario reactivado.',
        ]);
    }
}
