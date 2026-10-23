<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board columns, one set per company. The four defaults are created by
 * TaskStatusService::ensureDefaults() rather than seeded here: a migration
 * cannot know which companies exist when a module is enabled, and the
 * reversible-migration contract keeps data writes out of `up()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_task_statuses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->string('name');
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('position');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_task_statuses');
    }
};
