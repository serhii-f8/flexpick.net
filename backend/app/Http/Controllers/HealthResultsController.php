<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Symfony\Component\HttpFoundation\Response;

class HealthResultsController extends Controller
{
    /** Statuses that mean "this check is not passing". */
    private const FAILING = ['failed', 'crashed'];

    public function __invoke(Request $request, ResultStore $resultStore): JsonResponse
    {
        $this->assertTokenIsValid($request);

        $results = $resultStore->latestResults();

        if ($results === null) {
            return $this->respond(['status' => 'no_results'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $finishedAt = Carbon::instance($results->finishedAt);

        $stale = $finishedAt->lt(
            now()->subMinutes((int) config('health.flexpick.result_freshness_minutes'))
        );

        $checks = collect($results->storedCheckResults);

        $paging = $checks->contains(fn (StoredCheckResult $result) => $this->isPagingFailure($result));

        return $this->respond([
            'finishedAt' => $finishedAt->toIso8601String(),
            'stale' => $stale,
            'checkResults' => $checks->map(fn (StoredCheckResult $result) => [
                'name' => $result->name,
                'status' => $result->status,
                'band' => $this->bandFor($result->name),
                'message' => $result->notificationMessage,
            ])->values()->all(),
        ], ($stale || $paging) ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }

    private function assertTokenIsValid(Request $request): void
    {
        $expected = (string) config('health.flexpick.endpoint_token');

        // 404 rather than 401: the endpoint should not advertise its existence.
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('token'))) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    private function isPagingFailure(StoredCheckResult $result): bool
    {
        if (! in_array($result->status, self::FAILING, true)) {
            return false;
        }

        return in_array(
            $this->bandFor($result->name),
            (array) config('health.flexpick.paging_bands'),
            true
        );
    }

    private function bandFor(string $name): string
    {
        $bands = (array) config('health.flexpick.bands');

        return (string) ($bands[$name] ?? config('health.flexpick.default_band'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload, int $status): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
