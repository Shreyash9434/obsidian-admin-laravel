<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 1)->default('1');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'organizations_tenant_code_unique');
            $table->unique(['tenant_id', 'name'], 'organizations_tenant_name_unique');
            $table->index(['tenant_id', 'status', 'deleted_at'], 'organizations_tenant_status_deleted_at_index');
        });

        Schema::create('teams', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 1)->default('1');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'teams_organization_code_unique');
            $table->unique(['organization_id', 'name'], 'teams_organization_name_unique');
            $table->index(['tenant_id', 'organization_id', 'status', 'deleted_at'], 'teams_tenant_org_status_deleted_at_index');
        });

        Schema::table('users', static function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('tenant_id');
            $table->foreignId('team_id')->nullable()->after('organization_id');

            $table->index(['tenant_id', 'organization_id'], 'users_tenant_organization_index');
            $table->index(['tenant_id', 'team_id'], 'users_tenant_team_index');

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropForeign(['team_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex('users_tenant_team_index');
            $table->dropIndex('users_tenant_organization_index');
            $table->dropColumn(['team_id', 'organization_id']);
        });

        Schema::dropIfExists('teams');
        Schema::dropIfExists('organizations');
    }
};
