<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks belong to a project or stand alone against a customer. `customer_id` is
 * denormalised from the project (or set directly for a standalone task) because
 * it is what the billing screen groups by. `board_position` is fractional so a
 * drag rewrites a single row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_tasks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('task_status_id');
            $table->unsignedInteger('number');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('assignee_id')->nullable();
            $table->string('priority', 16)->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->boolean('billable')->default(true);
            $table->bigInteger('rate')->nullable();
            $table->decimal('board_position', 20, 10)->default(0);
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('creator_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'task_status_id', 'board_position']);
            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'assignee_id']);
            $table->index(['company_id', 'customer_id']);
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_tasks');
    }
};
