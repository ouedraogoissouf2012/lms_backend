<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\TeacherClassesQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/lms/teacher/classes — classes locales de l'enseignant (#712).
 */
final class LMSTeacherClassesController extends AuthenticatedController
{
    public function __construct(
        private readonly TeacherClassesQueryService $query,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json([
            'success' => true,
            'data' => $this->query->listFor($user),
        ]);
    }
}
