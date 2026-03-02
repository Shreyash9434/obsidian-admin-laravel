<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Http\Controllers;

use App\Domains\Access\Models\User;
use App\Domains\Shared\Http\Controllers\ApiController;
use App\Domains\Shared\Services\ApiCacheService;
use App\Domains\System\Services\AuditLogService;
use App\Domains\Tenant\Http\Resources\TeamListResource;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Team;
use App\Domains\Tenant\Services\TeamService;
use App\Domains\Tenant\Services\TenantContextService;
use App\Http\Requests\Api\Team\ListTeamsRequest;
use App\Http\Requests\Api\Team\StoreTeamRequest;
use App\Http\Requests\Api\Team\UpdateTeamRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TeamController extends ApiController
{
    public function __construct(
        private readonly TeamService $teamService,
        private readonly AuditLogService $auditLogService,
        private readonly TenantContextService $tenantContextService,
        private readonly ApiCacheService $apiCacheService,
    ) {}

    public function list(ListTeamsRequest $request): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, ['team.view', 'team.manage'], 'viewAny');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $validated = $request->validated();

        $current = (int) ($validated['current'] ?? 1);
        $size = (int) ($validated['size'] ?? 10);
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $status = (string) ($validated['status'] ?? '');
        $organizationId = isset($validated['organizationId']) ? (int) $validated['organizationId'] : null;

        if ($organizationId !== null && ! $this->organizationExistsInTenant($tenantId, $organizationId)) {
            return $this->error(self::PARAM_ERROR_CODE, 'Organization not found');
        }

        $query = Team::query()
            ->with('organization:id,name')
            ->withCount('users')
            ->select(['id', 'tenant_id', 'organization_id', 'code', 'name', 'description', 'status', 'sort', 'created_at', 'updated_at'])
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

        if ($organizationId !== null && $organizationId > 0) {
            $query->where('organization_id', $organizationId);
        }

        if ($this->hasCursorPagination($validated)) {
            $page = $this->cursorPaginateById(
                clone $query,
                $size,
                (string) ($validated['cursor'] ?? ''),
                false
            );

            $records = TeamListResource::collection($page['records'])->resolve($request);

            return $this->success([
                'paginationMode' => 'cursor',
                'size' => $page['size'],
                'hasMore' => $page['hasMore'],
                'nextCursor' => $page['nextCursor'],
                'records' => $records,
            ]);
        }

        $total = (clone $query)->count();
        $records = TeamListResource::collection(
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
        $context = $this->resolveTenantScopedContext($request, ['team.view', 'team.manage'], 'viewAny');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $organizationId = (int) $request->query('organizationId', 0);
        if ($organizationId > 0 && ! $this->organizationExistsInTenant($tenantId, $organizationId)) {
            return $this->error(self::PARAM_ERROR_CODE, 'Organization not found');
        }

        $records = $this->apiCacheService->remember(
            'teams',
            sprintf('all|tenant:%d|organization:%d', $tenantId, max(0, $organizationId)),
            static function () use ($tenantId, $organizationId): array {
                $query = Team::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', '1');

                if ($organizationId > 0) {
                    $query->where('organization_id', $organizationId);
                }

                return $query
                    ->orderBy('sort')
                    ->orderBy('id')
                    ->get(['id', 'organization_id', 'code', 'name'])
                    ->map(static function (Team $team): array {
                        return [
                            'id' => $team->id,
                            'organizationId' => (string) $team->organization_id,
                            'teamCode' => (string) $team->code,
                            'teamName' => (string) $team->name,
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

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, 'team.manage', 'manage');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        /** @var \App\Domains\Access\Models\User $user */
        $user = $context['user'];
        $dto = $request->toDTO();

        if (! $this->organizationExistsInTenant($tenantId, $dto->organizationId)) {
            return $this->error(self::PARAM_ERROR_CODE, 'Organization not found');
        }

        $uniqueError = $this->validateTeamUniqueness($dto->organizationId, $dto->teamCode, $dto->teamName);
        if ($uniqueError !== null) {
            return $this->error(self::PARAM_ERROR_CODE, $uniqueError);
        }

        return $this->withIdempotency($request, $user, function () use ($tenantId, $dto, $user, $request): JsonResponse {
            $team = $this->teamService->create($tenantId, $dto);

            $this->auditLogService->record(
                action: 'team.create',
                auditable: $team,
                actor: $user,
                request: $request,
                newValues: [
                    'organizationId' => (int) $team->organization_id,
                    'teamCode' => (string) $team->code,
                    'teamName' => (string) $team->name,
                    'status' => (string) $team->status,
                    'sort' => (int) ($team->sort ?? 0),
                ],
                tenantId: $tenantId,
            );

            return $this->success([
                'id' => $team->id,
                'organizationId' => (string) $team->organization_id,
                'teamCode' => (string) $team->code,
                'teamName' => (string) $team->name,
                'status' => (string) $team->status,
                'sort' => (int) ($team->sort ?? 0),
            ], 'Team created');
        });
    }

    public function update(UpdateTeamRequest $request, int $id): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, 'team.manage', 'manage');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $team = Team::query()
            ->where('tenant_id', $tenantId)
            ->withCount('users')
            ->find($id);

        if (! $team) {
            return Team::query()->whereKey($id)->exists()
                ? $this->error(self::FORBIDDEN_CODE, 'Forbidden')
                : $this->error(self::PARAM_ERROR_CODE, 'Team not found');
        }

        $optimisticLockError = $this->ensureOptimisticLock($request, $team, 'Team');
        if ($optimisticLockError) {
            return $optimisticLockError;
        }

        $dto = $request->toDTO();
        if (! $this->organizationExistsInTenant($tenantId, $dto->organizationId)) {
            return $this->error(self::PARAM_ERROR_CODE, 'Organization not found');
        }

        $organizationChanged = (int) $team->organization_id !== $dto->organizationId;
        if ($organizationChanged && (int) ($team->users_count ?? 0) > 0) {
            return $this->error(self::PARAM_ERROR_CODE, 'Team has assigned users and cannot move organization');
        }

        $uniqueError = $this->validateTeamUniqueness($dto->organizationId, $dto->teamCode, $dto->teamName, $team->id);
        if ($uniqueError !== null) {
            return $this->error(self::PARAM_ERROR_CODE, $uniqueError);
        }

        /** @var \App\Domains\Access\Models\User $user */
        $user = $context['user'];
        $oldValues = [
            'organizationId' => (int) $team->organization_id,
            'teamCode' => (string) $team->code,
            'teamName' => (string) $team->name,
            'status' => (string) $team->status,
            'sort' => (int) ($team->sort ?? 0),
        ];

        $team = $this->teamService->update($team, $dto);

        $this->auditLogService->record(
            action: 'team.update',
            auditable: $team,
            actor: $user,
            request: $request,
            oldValues: $oldValues,
            newValues: [
                'organizationId' => (int) $team->organization_id,
                'teamCode' => (string) $team->code,
                'teamName' => (string) $team->name,
                'status' => (string) $team->status,
                'sort' => (int) ($team->sort ?? 0),
            ],
            tenantId: $tenantId,
        );

        return $this->success([
            'id' => $team->id,
            'organizationId' => (string) $team->organization_id,
            'teamCode' => (string) $team->code,
            'teamName' => (string) $team->name,
            'status' => (string) $team->status,
            'sort' => (int) ($team->sort ?? 0),
            'version' => (string) ($team->updated_at?->copy()->setTimezone('UTC')->timestamp ?? 0),
            'updateTime' => \App\Support\ApiDateTime::formatForRequest($team->updated_at, $request),
        ], 'Team updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $context = $this->resolveTenantScopedContext($request, 'team.manage', 'manage');
        if (! $context['ok']) {
            return $this->error($context['code'], $context['msg']);
        }

        $tenantId = $context['tenantId'];
        $team = Team::query()
            ->where('tenant_id', $tenantId)
            ->withCount('users')
            ->find($id);

        if (! $team) {
            return Team::query()->whereKey($id)->exists()
                ? $this->error(self::FORBIDDEN_CODE, 'Forbidden')
                : $this->error(self::PARAM_ERROR_CODE, 'Team not found');
        }

        if ((int) ($team->users_count ?? 0) > 0) {
            return $this->error(self::PARAM_ERROR_CODE, 'Team has assigned users');
        }

        /** @var \App\Domains\Access\Models\User $user */
        $user = $context['user'];
        $oldValues = [
            'organizationId' => (int) $team->organization_id,
            'teamCode' => (string) $team->code,
            'teamName' => (string) $team->name,
            'status' => (string) $team->status,
            'sort' => (int) ($team->sort ?? 0),
        ];

        $this->teamService->delete($team);

        $this->auditLogService->record(
            action: 'team.delete',
            auditable: $team,
            actor: $user,
            request: $request,
            oldValues: $oldValues,
            tenantId: $tenantId,
        );

        return $this->success([], 'Team deleted');
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
        if (! Gate::forUser($user)->allows($ability, Team::class)) {
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

    private function organizationExistsInTenant(int $tenantId, int $organizationId): bool
    {
        if ($organizationId <= 0) {
            return false;
        }

        return Organization::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($organizationId)
            ->exists();
    }

    private function validateTeamUniqueness(int $organizationId, string $code, string $name, ?int $ignoreId = null): ?string
    {
        $codeExistsQuery = Team::query()
            ->where('organization_id', $organizationId)
            ->where('code', $code);
        $nameExistsQuery = Team::query()
            ->where('organization_id', $organizationId)
            ->where('name', $name);

        if (is_int($ignoreId) && $ignoreId > 0) {
            $codeExistsQuery->where('id', '!=', $ignoreId);
            $nameExistsQuery->where('id', '!=', $ignoreId);
        }

        if ($codeExistsQuery->exists()) {
            return 'Team code already exists in selected organization';
        }

        if ($nameExistsQuery->exists()) {
            return 'Team name already exists in selected organization';
        }

        return null;
    }
}
