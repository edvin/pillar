<?php
// @codeCoverageIgnoreStart
namespace Pillar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Pillar\Health\PillarHealth;

final class HealthController extends Controller
{
    public function __invoke(PillarHealth $health): JsonResponse
    {
        $result = $health->check();

        $statusCode = match ($result['status']) {
            'ok', 'degraded' => 200,
            default => 503,
        };

        return response()->json($result, $statusCode);
    }
}
// @codeCoverageIgnoreEnd