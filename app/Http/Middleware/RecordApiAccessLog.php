<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\System\Services\ApiAccessLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordApiAccessLog
{
    private const STARTED_AT_KEY = '_api_access_started_at';

    public function __construct(private readonly ApiAccessLogService $apiAccessLogService) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT_KEY, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $startedAt = (float) ($request->attributes->get(self::STARTED_AT_KEY, microtime(true)) ?? microtime(true));
        $durationMs = (int) round(max(0, (microtime(true) - $startedAt) * 1000));

        try {
            $this->apiAccessLogService->record($request, $response, $durationMs);
        } catch (Throwable $exception) {
            Log::warning('api_access_log.write_failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
