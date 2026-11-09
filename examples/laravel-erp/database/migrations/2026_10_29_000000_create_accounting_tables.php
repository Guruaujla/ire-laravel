<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per workspace: its runtime tenant and accounting connection.
        Schema::create('accounting_links', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_id')->unique();
            $table->string('tenant_id');
            $table->string('connection_id')->nullable();
            $table->string('connect_state')->nullable();
            $table->timestamps();
        });

        // Outbox: one row per business event. The request is stored BEFORE the
        // first submit, so a retry always resends identical bytes and key.
        Schema::create('accounting_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_id');
            $table->string('operation');
            $table->string('erp_id');
            $table->string('idempotency_key', 128);
            $table->json('request');
            $table->string('job_id')->nullable()->index();
            $table->string('polling_url')->nullable();
            $table->string('status')->default('pending');
            $table->string('provider_id')->nullable();
            $table->json('lines')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('documents_read_at')->nullable(); // provider reads after completion done
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'erp_id']);
        });

        // Current provider state of each invoice and payment, per connection
        // (a reconnect to another company starts fresh). Written only by
        // RecordProviderChange::refresh(), which reads and stores under this
        // row's lock, so the row is always the latest read.
        Schema::create('accounting_documents', function (Blueprint $table) {
            $table->id();
            // Bounded lengths and a short index name keep the unique key
            // within MySQL's 64-character name and 3072-byte utf8mb4 key limits.
            $table->string('workspace_id', 191);
            $table->string('connection_id', 191);
            $table->string('document_type', 32);
            $table->string('erp_id', 128); // the API's erp_id limit
            $table->string('provider_id')->nullable();
            $table->string('revision')->nullable(); // send as expected_revision on the next write
            $table->string('status');
            $table->string('balance')->nullable();
            $table->timestamp('provider_updated_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'connection_id', 'document_type', 'erp_id'], 'accounting_documents_identity_unique');
        });

        // Upgrading from the earlier quickbooks() example: keep each
        // workspace's tenant and connection. quickbooks_syncs stays as history;
        // AccountingSyncService refuses to re-create an invoice listed there.
        if (Schema::hasTable('quickbooks_links')) {
            DB::table('accounting_links')->insertUsing(
                ['workspace_id', 'tenant_id', 'connection_id', 'connect_state', 'created_at', 'updated_at'],
                DB::table('quickbooks_links')->select(['workspace_id', 'tenant_id', 'connection_id', 'connect_state', 'created_at', 'updated_at']),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_documents');
        Schema::dropIfExists('accounting_syncs');
        Schema::dropIfExists('accounting_links');
    }
};
