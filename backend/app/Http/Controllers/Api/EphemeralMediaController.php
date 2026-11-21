<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEphemeralImageRequest;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles the "view once" image flow.
 *
 * An ephemeral image is uploaded once, its single-use token is handed to the
 * recipient, and the file is destroyed as soon as it has been opened.
 */
class EphemeralMediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * Upload a view-once image and return its single-use token.
     */
    public function store(StoreEphemeralImageRequest $request): JsonResponse
    {
        [$asset, $token] = $this->media->storeEphemeral(
            $request->file('image'),
            $request->user(),
            $request->integer('conversation_id') ?: null,
        );

        return response()->json([
            'uuid' => $asset->uuid,
            'token' => $token,
            'url' => route('api.media.ephemeral.show', ['token' => $token]),
            'width' => $asset->width,
            'height' => $asset->height,
            'size' => $asset->size,
            'expires_at' => $asset->expires_at?->toIso8601String(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Redeem a view-once token and receive the image exactly once.
     *
     * The token is invalidated in the same transaction that reads it, so a
     * replayed request always fails.
     */
    public function show(Request $request, string $token): Response
    {
        [$asset, $binary] = $this->media->redeemEphemeral($token);

        if ($asset === null || $binary === null) {
            return response()->json([
                'message' => 'Esta imagen ya no está disponible.',
            ], Response::HTTP_GONE);
        }

        return $this->imageResponse($asset, $binary);
    }

    /**
     * Serve a view-once image through the message it belongs to.
     *
     * Only the recipient may open it, and the asset is consumed in the same
     * request, so the image can never be retrieved twice.
     */
    public function asset(Request $request, string $uuid): Response
    {
        $asset = MediaAsset::query()
            ->where('uuid', $uuid)
            ->where('kind', 'ephemeral')
            ->first();

        $message = $asset === null
            ? null
            : Message::query()
                ->where('media_asset_id', $asset->id)
                ->with('conversation')
                ->first();

        if ($asset === null || $message === null) {
            return response()->json([
                'message' => 'Esta imagen ya no está disponible.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->authorize('view', $message->conversation);

        if ($request->user()?->id === $message->user_id) {
            return response()->json([
                'message' => 'Solo el destinatario puede abrir esta imagen.',
            ], Response::HTTP_FORBIDDEN);
        }

        $binary = $this->media->consume($asset);

        if ($binary === null) {
            return response()->json([
                'message' => 'Esta imagen ya no está disponible.',
            ], Response::HTTP_GONE);
        }

        return $this->imageResponse($asset, $binary);
    }

    /**
     * Build the response for an image that must never be cached.
     */
    private function imageResponse(MediaAsset $asset, string $binary): Response
    {
        return response($binary, Response::HTTP_OK, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
