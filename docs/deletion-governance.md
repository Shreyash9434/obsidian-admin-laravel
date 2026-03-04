# Deletion Governance (Backend)

This document defines a safe and consistent deletion lifecycle for multi-tenant resources.

## Lifecycle

1. `active` -> normal business usage.
2. `inactive` -> immediately blocked for business actions.
3. `soft_deleted` -> logically removed, recoverable within retention window.
4. `hard_deleted` -> physically purged by scheduled job.

Default policy: do not hard-delete directly from synchronous API.

## Strategy Matrix

| Resource | Disable First | Soft Delete | Hard Delete | Guard Rules |
| --- | --- | --- | --- | --- |
| Tenant | Required | Optional (recommended) | Scheduled only | must be super-admin, no protected seed tenant, no unresolved critical dependencies |
| Organization | Required | Recommended | Scheduled only | tenant-scope enforced, block if active child teams/users exist |
| Team | Required | Recommended | Scheduled only | tenant-scope enforced, block if active users bound |
| User | Required | Recommended | Scheduled only | revoke tokens/sessions first, preserve audit trail |
| Role | Required | Recommended | Scheduled only | block if users still assigned, reserved role codes cannot be deleted |
| Permission | Optional | Recommended | Scheduled only | block if still assigned to roles, reserved permission codes cannot be deleted |

## API Response Contract

Use the standard envelope:

```json
{
  "code": "0000",
  "msg": "ok",
  "data": {
    "action": "deactivated",
    "resource": "role",
    "resourceId": "12",
    "recoverableUntil": "2026-04-01T00:00:00Z"
  },
  "requestId": "req_xxx",
  "traceId": "trace_xxx"
}
```

### Recommended `data.action`

- `deactivated`: status changed to inactive.
- `soft_deleted`: marked with `deleted_at`.
- `queued_for_hard_delete`: accepted for async purge.

### Conflict Contract (`code=1009`)

When deletion is blocked by dependencies:

```json
{
  "code": "1009",
  "msg": "Delete conflict",
  "data": {
    "resource": "permission",
    "resourceId": "9",
    "reason": "dependency_exists",
    "dependencies": {
      "roles": 3
    },
    "suggestedAction": "detach_and_retry"
  }
}
```

## Backend Guard Checklist

- Scope guard: never allow cross-tenant deletes.
- Existence guard: return `4040` when target does not exist in current scope.
- Protected guard: return `1003` for reserved/platform-protected records.
- Dependency guard: return `1009` with dependency counts.
- Session guard: when user is deactivated/deleted, revoke all active tokens.
- Audit guard: write audit log for disable, soft delete, restore, hard delete.

## Implementation Template

Use a two-step API:

- `PATCH /api/<resource>/{id}/status` for immediate disable/enable.
- `DELETE /api/<resource>/{id}` for soft delete request.

Controller skeleton:

```php
public function destroy(Request $request, int $id): JsonResponse
{
    $auth = $this->authenticateAndAuthorize($request, 'access-api', 'P_RESOURCE_MANAGE');
    if ($auth->failed()) {
        return $this->error($auth->code(), $auth->message());
    }

    $actor = $auth->user();
    $result = $this->resourceDeletionService->softDelete($id, $actor, $request);

    if (! $result->ok) {
        return $this->error($result->code, $result->message, $result->data);
    }

    return $this->success([
        'action' => 'soft_deleted',
        'resource' => 'resource',
        'resourceId' => (string) $id,
        'recoverableUntil' => $result->recoverableUntil,
    ]);
}
```

Service decision skeleton:

```php
if ($target->status === '1') {
    $target->forceFill(['status' => '2'])->save();
    return Result::ok(action: 'deactivated');
}

if ($this->hasDependencies($target)) {
    return Result::conflict(
        code: '1009',
        message: 'Delete conflict',
        data: ['reason' => 'dependency_exists', 'dependencies' => $this->dependencyCount($target)]
    );
}

$target->delete(); // soft delete
return Result::ok(action: 'soft_deleted');
```

## Scheduled Hard Delete

Use a queue/command to purge expired soft-deleted records:

- keep retention in config (`security.deletion.retention_days`).
- run daily command (`php artisan app:purge-soft-deleted`).
- write audit entry for purge operation.

