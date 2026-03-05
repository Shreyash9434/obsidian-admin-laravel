<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'log_type')) {
            Schema::table('audit_logs', static function (Blueprint $table): void {
                $table->string('log_type', 32)->default('operation');
                $table->index(['log_type', 'created_at'], 'audit_logs_log_type_created_at_index');
                $table->index(['tenant_id', 'log_type', 'id'], 'audit_logs_tenant_log_type_id_index');
            });
        }

        DB::statement(
            <<<'SQL'
            UPDATE audit_logs
            SET log_type = CASE
                WHEN action LIKE 'auth.%'
                    OR action = 'user.verify_email'
                    OR action LIKE 'user.2fa.%'
                    THEN 'login'
                WHEN action LIKE 'role.%'
                    OR action LIKE 'permission.%'
                    OR action = 'user.assign_role'
                    OR action LIKE 'audit.policy.%'
                    OR action LIKE 'audit-policy.%'
                    THEN 'permission'
                WHEN action LIKE 'system.config.%'
                    OR action LIKE 'theme.config.%'
                    OR action LIKE 'feature-flag.%'
                    THEN 'operation'
                WHEN action LIKE 'api.%'
                    OR action LIKE 'request.%'
                    THEN 'api'
                WHEN action LIKE 'user.%'
                    OR action LIKE 'tenant.%'
                    OR action LIKE 'organization.%'
                    OR action LIKE 'team.%'
                    OR action LIKE 'language.translation.%'
                    THEN 'data'
                ELSE 'operation'
            END
            SQL
        );
    }

    public function down(): void
    {
        if (! Schema::hasColumn('audit_logs', 'log_type')) {
            return;
        }

        Schema::table('audit_logs', static function (Blueprint $table): void {
            $table->dropIndex('audit_logs_log_type_created_at_index');
            $table->dropIndex('audit_logs_tenant_log_type_id_index');
            $table->dropColumn('log_type');
        });
    }
};
