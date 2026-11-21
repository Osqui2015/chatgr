<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets users report other users or specific messages.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ModerationService $moderation) {}

    /**
     * File a report.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reported_user_id' => ['required', 'integer', 'exists:users,id'],
            'message_id' => ['nullable', 'integer', 'exists:messages,id'],
            'reason' => ['required', Rule::in(['spam', 'harassment', 'nudity', 'violence', 'other'])],
            'details' => ['nullable', 'string', 'max:2000'],
        ], [
            'reported_user_id.required' => 'Indica el usuario que quieres denunciar.',
            'reported_user_id.exists' => 'El usuario no existe.',
            'reason.required' => 'Indica el motivo de la denuncia.',
            'reason.in' => 'El motivo indicado no es válido.',
            'details.max' => 'La descripción es demasiado larga.',
        ]);

        /** @var User $target */
        $target = User::query()->findOrFail($validated['reported_user_id']);

        $this->authorize('report', $target);

        $message = isset($validated['message_id'])
            ? Message::query()->find($validated['message_id'])
            : null;

        $report = $this->moderation->report(
            $request->user(),
            $target,
            $validated['reason'],
            $validated['details'] ?? null,
            $message,
        );

        return response()->json([
            'message' => 'Denuncia registrada. Nuestro equipo la revisará.',
            'report_id' => $report->id,
        ], 201);
    }

    /**
     * List the reports filed by the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $reports = Report::query()
            ->where('reporter_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'reason', 'status', 'created_at', 'resolved_at']);

        return response()->json([
            'data' => $reports,
        ]);
    }
}
