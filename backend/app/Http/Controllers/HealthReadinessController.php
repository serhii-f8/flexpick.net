<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReachable(),
            'cache' => $this->cacheIsWritable(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()
            ->json(['ready' => $ready, 'checks' => $checks],
                $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Cache-Control', 'no-store, private');
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsWritable(): bool
    {
        try {
            $key = 'health:ready:'.Str::random(12);
            Cache::put($key, '1', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return $value === '1';
        } catch (Throwable) {
            return false;
        }
    }
}
