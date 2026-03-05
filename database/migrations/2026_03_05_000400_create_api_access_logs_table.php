<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_access_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 128)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('route_name', 180)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('request_size')->nullable();
            $table->unsignedInteger('response_size')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'api_access_logs_created_at_index');
            $table->index(['status_code', 'created_at'], 'api_access_logs_status_created_at_index');
            $table->index(['tenant_id', 'created_at'], 'api_access_logs_tenant_created_at_index');
            $table->index(['request_id'], 'api_access_logs_request_id_index');
            $table->index(['path', 'created_at'], 'api_access_logs_path_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_access_logs');
    }
};
