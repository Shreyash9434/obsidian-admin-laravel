<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Http\Controllers;

use App\Domains\Access\Models\User;
use App\Domains\Shared\Http\Controllers\ApiController;
use App\Domains\Shared\Services\ApiCacheService;
use App\Domains\System\Services\AuditLogService;
use App\Domains\Tenant\Http\Resources\OrganizationListResource;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\OrganizationService;
use App\Domains\Tenant\Services\TenantContextService;
use App\Http\Requests\Api\Organization\ListOrganizationsRequest;
use App\Http\Requests\Api\Organization\StoreOrganizationRequest;
use App\Http\Requests\Api\Organization\UpdateOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends ApiController
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly AuditLogService $auditLogService,
        private readonly TenantContextService $tenantContextService,
        private readonly ApiCacheService $apiCacheService,
    ) {}

    public function list(ListOrganizationsRequest $request): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, ['organization.view', 'organization.manage'], 'viewAny');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $validated = $request->validated();

        $current = (int) ($validated['current'] ?? 1);
        $size = (int) ($validated['size'] ?? 10);
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $status = (string) ($validated['status'] ?? '');

        $query = Organization::query()
            ->with('tenant:id,name')
            ->withCount(['teams', 'users'])
            ->select(['id', 'tenant_id', 'code', 'name', 'description', 'status', 'sort', 'created_at', 'updated_at'])
            ->where('tenant_id', $tenantId);

        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('code', 'like', '%'.$keyword.'%')
                    ->orWhere('name', 'like', '%'.$keyword.'%');
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($this->hasCursorPagination($validated)) {
            $page = $this->cursorPaginateById(
                clone $query,
                $size,
                (string) ($validated['cursor'] ?? ''),
                false
            );

            $records = OrganizationListResource::collection($page['records'])->resolve($request);

            return $this->success([
                'paginationMode' => 'cursor',
                'size' => $page['size'],
                'hasMore' => $page['hasMore'],
                'nextCursor' => $page['nextCursor'],
                'records' => $records,
            ]);
        }

        $total = (clone $query)->count();
        $records = OrganizationListResource::collection(
            $query->orderBy('sort')
                ->orderBy('id')
                ->forPage($current, $size)
                ->get()
        )->resolve($request);

        return $this->success([
            'current' => $current,
            'size' => $size,
            'total' => $total,
            'records' => $records,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, ['organization.view', 'organization.manage'], 'viewAny');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $records = $this->apiCacheService->remember(
            'organizations',
            'all|tenant:'.$tenantId,
            static function () use ($tenantId): array {
                return Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', '1')
                    ->orderBy('sort')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name'])
                    ->map(static function (Organization $organization): array {
                        return [
                            'id' => $organization->id,
                            'organizationCode' => (string) $organization->code,
                            'organizationName' => (string) $organization->name,
                        ];
                    })
                    ->values()
                    ->all();
            }
        );

        return $this->success([
            'records' => $records,
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, 'organization.manage', 'manage');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        /** @var \App\Domains\Access\Models\User $user */
        $user = $context['user'];
        $dto = $request->toDTO();

        $uniqueError = $this->validateTenantUniqueness($tenantId, $dto->organizationCode, $dto->organizationName);
        if ($uniqueError !== null) {
            return $this->error(self::PARAM_ERROR_CODE, $uniqueError);
        }

        return $this->withIdempotency($request, $user, function () use ($tenantId, $dto, $user, $request): JsonResponse {
            $organization = $this->organizationService->create($tenantId, $dto);

            $this->auditLogService->record(
                action: 'organization.create',
                auditable: $organization,
                actor: $user,
                request: $request,
                newValues: [
                    'organizationCode' => $organization->code,
                    'organizationName' => $organization->name,
                    'tenantId' => (int) $organization->tenant_id,
                    'status' => (string) $organization->status,
                    'sort' => (int) ($organization->sort ?? 0),
                ],
                tenantId: $tenantId,
            );

            return $this->success([
                'id' => $organization->id,
                'organizationCode' => (string) $organization->code,
                'organizationName' => (string) $organization->name,
                'status' => (string) $organization->status,
                'sort' => (int) ($organization->sort ?? 0),
            ], 'Organization created');
        });
    }

    public function update(UpdateOrganizationRequest $request, int $id): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, 'organization.manage', 'manage');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $organization = Organization::query()
            ->where('tenant_id', $tenantId)
            ->find($id);

        if (! $organization) {
            return Organization::query()->whereKey($id)->exists()
                ? $this->error(self::FORBIDDEN_CODE, 'Forbidden')
                : $this->error(self::PARAM_ERROR_CODE, 'Organization not found');
        }

        $optimisticLockError = $this->ensureOptimisticLock($request, $organization, 'Organization');
        if ($optimisticLockError) {
            return $optimisticLockError;
        }

        $dto = $request->toDTO();
        $uniqueError = $this->validateTenantUniqueness($tenantId, $dto->organizationCode, $dto->organizationName, $organization->id);
        if ($uniqueError !== null) {
            return $this->error(self::PARAM_ERROR_CODE, $uniqueError);
        }

        /** @var \App\Domains\Access\Models\User $user */
        $user = $context['user'];
        $oldValues = [
            'organizationCode' => (string) $organization->code,
            'organizationName' => (string) $organization->name,
            'status' => (string) $organization->status,
            'sort' => (int) ($organization->sort ?? 0),
        ];

        $organization = $this->organizationService->update($organization, $dto);

        $this->auditLogService->record(
            action: 'organization.update',
            auditable: $organization,
            actor: $user,
            request: $request,
            oldValues: $oldValues,
            newValues: [
                'organizationCode' => (string) $organization->code,
                'organizationName' => (string) $organization->name,
                'status' => (string) $organization->status,
                'sort' => (int) ($organization->sort ?? 0),
            ],
            tenantId: $tenantId,
        );

        return $this->success([
            'id' => $organization->id,
            'organizationCode' => (string) $organization->code,
            'organizationName' => (string) $organization->name,
            'status' => (string) $organization->status,
            'sort' => (int) ($organization->sort ?? 0),
            'version' => (string) ($organization->updated_at?->copy()->setTimezone('UTC')->timestamp ?? 0),
            'updateTime' => \App\Support\ApiDateTime::formatForRequest($organization->updated_at, $request),
        ], 'Organization updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, 'organization.manage', 'manage');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $organization = Organization::query()
            ->where('tenant_id', $tenantId)
            ->withCount(['teams', 'users'])
            ->find($id);

        if (! $organization) {
            return Organization::query()->whereKey($id)->exists()
                ? $this->error(self::FORBIDDEN_CODE, 'Forbidden')
                : $this->error(self::PARAM_ERROR_CODE, 'Organization not found');
        }

        if ((int) ($organization->teams_count ?? 0) > 0) {
            return $this->error(self::PARAM_ERROR_CODE, 'Organization has assigned teams');
        }

        if ((int) ($organization->users_count ?? 0) > 0) {
            return $this->error(self::PARAM_ERROR_CODE, 'Organization has assigned users');
        }

        /** @var \App\Domains\Access\Models\User $user */
        $user = $context['user'];
        $oldValues = [
            'organizationCode' => (string) $organization->code,
            'organizationName' => (string) $organization->name,
            'status' => (string) $organization->status,
            'sort' => (int) ($organization->sort ?? 0),
        ];

        $this->organizationService->delete($organization);

        $this->auditLogService->record(
            action: 'organization.delete',
            auditable: $organization,
            actor: $user,
            request: $request,
            oldValues: $oldValues,
            tenantId: $tenantId,
        );

        return $this->success([], 'Organization deleted');
    }

    /**
     * @param  string|list<string>  $permissionCode
     * @return array{ok: false, code: string, msg: string}|array{
     *   ok: true,
     *   code: string,
     *   msg: string,
     *   user: User,
     *   tenantId: int,
     *   tenantName: string
     * }
     */
    private function resolveTenantScopedContext(Request $request, string|array $permissionCode, string $ability): array
    {
        if (is_array($permissionCode)) {
            /** @var list<string> $permissionCodes */
            $permissionCodes = array_values(
                array_filter(
                    array_map(static fn (mixed $code): string => trim((string) $code), $permissionCode),
                    static fn (string $code): bool => $code !== ''
                )
            );
            if ($permissionCodes === []) {
                return [
                    'ok' => false,
                    'code' => self::FORBIDDEN_CODE,
                    'msg' => 'Forbidden',
                ];
            }
            $authResult = $this->authenticateAndAuthorizeAny($request, 'access-api', $permissionCodes);
        } else {
            $authResult = $this->authenticateAndAuthorize($request, 'access-api', $permissionCode);
        }

        if (! $authResult['ok']) {
            return $authResult;
        }

        $authUser = $authResult['user'] ?? null;
        if (! $authUser instanceof User) {
            return [
                'ok' => false,
                'code' => self::FORBIDDEN_CODE,
                'msg' => 'Forbidden',
            ];
        }

        $user = $authUser;
        if (! Gate::forUser($user)->allows($ability, Organization::class)) {
            return [
                'ok' => false,
                'code' => self::FORBIDDEN_CODE,
                'msg' => 'Forbidden',
            ];
        }

        $tenantContext = $this->tenantContextService->resolveTenantContext($request, $user);
        if (! $tenantContext['ok']) {
            return [
                'ok' => false,
                'code' => $tenantContext['code'],
                'msg' => $tenantContext['msg'],
            ];
        }

        $tenantId = $tenantContext['tenantId'] ?? null;
        if (! is_int($tenantId) || $tenantId <= 0) {
            return [
                'ok' => false,
                'code' => self::PARAM_ERROR_CODE,
                'msg' => 'Please select a tenant first',
            ];
        }

        return [
            'ok' => true,
            'code' => self::SUCCESS_CODE,
            'msg' => 'ok',
            'user' => $user,
            'tenantId' => $tenantId,
            'tenantName' => (string) ($tenantContext['tenantName'] ?? ''),
        ];
    }

    private function validateTenantUniqueness(int $tenantId, string $code, string $name, ?int $ignoreId = null): ?string
    {
        $codeExistsQuery = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code);
        $nameExistsQuery = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name);

        if (is_int($ignoreId) && $ignoreId > 0) {
            $codeExistsQuery->where('id', '!=', $ignoreId);
            $nameExistsQuery->where('id', '!=', $ignoreId);
        }

        if ($codeExistsQuery->exists()) {
            return 'Organization code already exists in selected tenant';
        }

        if ($nameExistsQuery->exists()) {
            return 'Organization name already exists in selected tenant';
        }

        return null;
    }
}
