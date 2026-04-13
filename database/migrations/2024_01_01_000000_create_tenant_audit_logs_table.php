<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenant-audit.connection');
    }

    public function up(): void
    {
        $table = config('tenant-audit.table', 'tenant_audit_logs');

        Schema::connection($this->getConnection())->create($table, function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->index(['user_type', 'user_id'], 'ta_user_morph_index');
            $table->string('event', 50);
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'ta_tenant_morph_index');
            $table->index(['tenant_id', 'event'], 'ta_tenant_event_index');
            $table->index('created_at', 'ta_created_at_index');
        });
    }

    public function down(): void
    {
        $table = config('tenant-audit.table', 'tenant_audit_logs');

        Schema::connection($this->getConnection())->dropIfExists($table);
    }
};
