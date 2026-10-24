<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time entries carry the authoritative `duration_minutes` and the rate resolved
 * when the entry was saved, so a later rate change never rewrites history.
 *
 * The unique index on `(company_id, running_user_id)` enforces "one running
 * timer per user per company" on MySQL, PostgreSQL and SQLite alike: all three
 * treat NULLs in a unique index as distinct, so stopped entries never collide
 * and no partial index or second table is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_time_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedInteger('user_id');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->text('description')->nullable();
            $table->boolean('billable')->default(true);
            $table->bigInteger('rate')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->unsignedInteger('currency_id')->nullable();
            $table->unsignedInteger('running_user_id')->nullable();
            $table->unsignedInteger('invoice_id')->nullable();
            $table->unsignedInteger('invoice_item_id')->nullable();
            $table->dateTime('invoiced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'running_user_id']);
            $table->index(['company_id', 'user_id', 'started_at']);
            $table->index(['company_id', 'billable', 'invoice_id']);
            $table->index(['company_id', 'task_id']);
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_time_entries');
    }
};
