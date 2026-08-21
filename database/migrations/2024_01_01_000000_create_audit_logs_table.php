<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->auditConnection())->create($this->auditTable(), function (Blueprint $table) {
            $table->bigIncrements('id');

            // --- Correlation -------------------------------------------------
            $table->string('correlation_id', 64)->index();
            $table->string('request_id', 64)->unique();
            $table->string('parent_request_id', 64)->nullable()->index();

            // --- Origin ------------------------------------------------------
            $table->string('service', 64)->index();
            $table->string('environment', 32)->nullable();
            $table->string('direction', 16)->default('inbound');

            // --- Request -----------------------------------------------------
            $table->string('method', 10);
            $table->string('path', 512)->index();
            $table->text('url');
            $table->string('route', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('user_type', 255)->nullable();

            // --- Response ----------------------------------------------------
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable()->index();
            $table->unsignedInteger('memory_peak_kb')->nullable();

            // --- Payloads ----------------------------------------------------
            $table->longText('request_headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->longText('query')->nullable();
            $table->longText('response_headers')->nullable();
            $table->longText('response_body')->nullable();

            // --- Diagnostics -------------------------------------------------
            $table->string('exception_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            $table->longText('tags')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            // "Show me everything that went wrong on this service today".
            $table->index(['service', 'status_code', 'created_at'], 'audit_logs_service_status_created_index');
            // Trace assembly across the whole journey.
            $table->index(['correlation_id', 'started_at'], 'audit_logs_correlation_started_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->auditConnection())->dropIfExists($this->auditTable());
    }

    protected function auditTable(): string
    {
        return config('audit-logger.drivers.database.table', 'audit_logs');
    }

    protected function auditConnection(): ?string
    {
        return config('audit-logger.drivers.database.connection');
    }
};
