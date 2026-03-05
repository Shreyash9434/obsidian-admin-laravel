<?php

declare(strict_types=1);

namespace App\Domains\System\Services;

use App\Domains\Access\Models\User;
use App\Domains\System\Models\AuditLog;
use App\Jobs\WriteAuditLogJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogService
{
    public function __construct(private readonly AuditPolicyService $auditPolicyService) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $action,
        Model|string $auditable,
        ?User $actor = null,
        ?Request $request = null,
        array $oldValues = [],
        array $newValues = [],
        ?int $tenantId = null
    ): void {
        $effectiveTenantId = $tenantId ?? ($actor?->tenant_id ? (int) $actor->tenant_id : null);
        if (! $this->auditPolicyService->shouldLog($action, $effectiveTenantId)) {
            return;
        }

        $auditableType = is_string($auditable) ? $auditable : $auditable::class;
        $auditableId = is_string($auditable) ? null : (int) ($auditable->getKey() ?? 0);
        $payload = $this->normalizePayload([
            'user_id' => $actor?->id,
            'tenant_id' => $effectiveTenantId,
            'action' => $action,
            'log_type' => $this->resolveLogType($action),
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId > 0 ? $auditableId : null,
            'old_values' => $oldValues !== [] ? $oldValues : null,
            'new_values' => $newValues !== [] ? $newValues : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => trim((string) ($request?->attributes->get('request_id', '') ?? '')) ?: null,
        ]);

        if ((bool) config('audit.queue.enabled', true)) {
            try {
                dispatch(new WriteAuditLogJob($payload));

                return;
            } catch (Throwable $exception) {
                Log::warning('audit.dispatch_failed_fallback_to_sync', [
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->writeAuditLogPayload($payload);
    }

    /**
     * @param  array{
     *   user_id: int|null,
     *   auditable_type: string,
     *   auditable_id: int|null,
     *   old_values: array<string, mixed>|null,
     *   new_values: array<string, mixed>|null,
     *   ip_address: string|null,
     *   user_agent: string|null,
     *   request_id: string|null,
     *   log_type?: string|null
     * }  $payload
     */
    public function recordPreparedPayload(string $action, array $payload, ?int $tenantId): void
    {
        if (! $this->auditPolicyService->shouldLog($action, $tenantId)) {
            return;
        }

        $this->writeAuditLogPayload($this->normalizePayload([
            'user_id' => $payload['user_id'],
            'tenant_id' => $tenantId,
            'action' => $action,
            'log_type' => $payload['log_type'] ?? $this->resolveLogType($action),
            'auditable_type' => (string) $payload['auditable_type'],
            'auditable_id' => $payload['auditable_id'],
            'old_values' => $payload['old_values'],
            'new_values' => $payload['new_values'],
            'ip_address' => $payload['ip_address'],
            'user_agent' => $payload['user_agent'],
            'request_id' => $payload['request_id'],
        ]));
    }

    /**
     * @param  array{
     *   user_id: int|null,
     *   tenant_id: int|null,
     *   action: string,
     *   log_type?: string|null,
     *   auditable_type: string,
     *   auditable_id: int|null,
     *   old_values: array<string, mixed>|null,
     *   new_values: array<string, mixed>|null,
     *   ip_address: string|null,
     *   user_agent: string|null,
     *   request_id: string|null
     * }  $payload
     */
    public function writeAuditLogPayload(array $payload): void
    {
        AuditLog::query()->create($payload);
    }

    /**
     * @param  array{
     *   user_id: int|null,
     *   tenant_id: int|null,
     *   action: string,
     *   log_type?: string|null,
     *   auditable_type: string,
     *   auditable_id: int|null,
     *   old_values: array<string, mixed>|null,
     *   new_values: array<string, mixed>|null,
     *   ip_address: string|null,
     *   user_agent: string|null,
     *   request_id: string|null
     * }  $payload
     * @return array{
     *   user_id: int|null,
     *   tenant_id: int|null,
     *   action: string,
     *   log_type: string,
     *   auditable_type: string,
     *   auditable_id: int|null,
     *   old_values: array<string, mixed>|null,
     *   new_values: array<string, mixed>|null,
     *   ip_address: string|null,
     *   user_agent: string|null,
     *   request_id: string|null
     * }
     */
    private function normalizePayload(array $payload): array
    {
        return [
            'user_id' => is_numeric($payload['user_id']) ? (int) $payload['user_id'] : null,
            'tenant_id' => is_numeric($payload['tenant_id']) ? (int) $payload['tenant_id'] : null,
            'action' => trim((string) $payload['action']),
            'log_type' => $this->normalizeLogType(
                ($payload['log_type'] ?? null) !== null ? (string) $payload['log_type'] : $this->resolveLogType((string) $payload['action'])
            ),
            'auditable_type' => trim((string) $payload['auditable_type']),
            'auditable_id' => is_numeric($payload['auditable_id']) ? (int) $payload['auditable_id'] : null,
            'old_values' => $this->sanitizeAuditValues($payload['old_values'] ?? null),
            'new_values' => $this->sanitizeAuditValues($payload['new_values'] ?? null),
            'ip_address' => ($payload['ip_address'] ?? null) !== null ? trim((string) $payload['ip_address']) : null,
            'user_agent' => ($payload['user_agent'] ?? null) !== null ? trim((string) $payload['user_agent']) : null,
            'request_id' => ($payload['request_id'] ?? null) !== null ? trim((string) $payload['request_id']) : null,
        ];
    }

    private function resolveLogType(string $action): string
    {
        $eventConfig = config('audit.events.'.trim($action));
        if (! is_array($eventConfig)) {
            return $this->inferLogType($action);
        }

        $configuredType = trim((string) ($eventConfig['log_type'] ?? ''));
        if ($configuredType !== '') {
            return $this->normalizeLogType($configuredType);
        }

        return $this->inferLogType($action);
    }

    private function normalizeLogType(string $logType): string
    {
        $normalized = strtolower(trim($logType));

        return match ($normalized) {
            AuditLog::LOG_TYPE_LOGIN => AuditLog::LOG_TYPE_LOGIN,
            AuditLog::LOG_TYPE_API => AuditLog::LOG_TYPE_API,
            AuditLog::LOG_TYPE_DATA => AuditLog::LOG_TYPE_DATA,
            AuditLog::LOG_TYPE_PERMISSION => AuditLog::LOG_TYPE_PERMISSION,
            default => AuditLog::LOG_TYPE_OPERATION,
        };
    }

    private function inferLogType(string $action): string
    {
        $normalizedAction = strtolower(trim($action));
        if ($normalizedAction === '') {
            return AuditLog::LOG_TYPE_OPERATION;
        }

        if (
            str_starts_with($normalizedAction, 'auth.')
            || $normalizedAction === 'user.verify_email'
            || str_starts_with($normalizedAction, 'user.2fa.')
        ) {
            return AuditLog::LOG_TYPE_LOGIN;
        }

        if (
            str_starts_with($normalizedAction, 'role.')
            || str_starts_with($normalizedAction, 'permission.')
            || $normalizedAction === 'user.assign_role'
            || str_starts_with($normalizedAction, 'audit.policy.')
            || str_starts_with($normalizedAction, 'audit-policy.')
        ) {
            return AuditLog::LOG_TYPE_PERMISSION;
        }

        if (
            str_starts_with($normalizedAction, 'user.')
            || str_starts_with($normalizedAction, 'tenant.')
            || str_starts_with($normalizedAction, 'organization.')
            || str_starts_with($normalizedAction, 'team.')
            || str_starts_with($normalizedAction, 'language.translation.')
        ) {
            return AuditLog::LOG_TYPE_DATA;
        }

        if (
            str_starts_with($normalizedAction, 'api.')
            || str_starts_with($normalizedAction, 'request.')
        ) {
            return AuditLog::LOG_TYPE_API;
        }

        return AuditLog::LOG_TYPE_OPERATION;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeAuditValues(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $masked = $this->maskSensitiveValues($values);

        return $this->shrinkPayloadIfOversized($masked);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function maskSensitiveValues(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $keyName = trim((string) $key);

            if ($this->isSensitiveKey($keyName)) {
                $result[$key] = $this->redactedText();

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result[$key] = $this->maskSensitiveValues($value);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(trim($key));
        if ($normalizedKey === '') {
            return false;
        }

        $sensitiveKeys = config('audit.payload.sensitive_keys', []);
        if (! is_array($sensitiveKeys)) {
            return false;
        }

        foreach ($sensitiveKeys as $item) {
            $needle = strtolower(trim((string) $item));
            if ($needle === '') {
                continue;
            }

            if (str_contains($normalizedKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function shrinkPayloadIfOversized(array $values): array
    {
        $maxBytes = max(256, (int) config('audit.payload.max_json_bytes', 8192));
        $encoded = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            return [
                '_truncated' => true,
                '_reason' => 'json_encode_failed',
            ];
        }

        $size = strlen($encoded);
        if ($size <= $maxBytes) {
            return $values;
        }

        return [
            '_truncated' => true,
            '_reason' => 'payload_oversize',
            '_maxBytes' => $maxBytes,
            '_sizeBytes' => $size,
            '_checksum' => hash('sha256', $encoded),
        ];
    }

    private function redactedText(): string
    {
        $text = trim((string) config('audit.payload.redacted_text', '[REDACTED]'));

        return $text !== '' ? $text : '[REDACTED]';
    }
}
