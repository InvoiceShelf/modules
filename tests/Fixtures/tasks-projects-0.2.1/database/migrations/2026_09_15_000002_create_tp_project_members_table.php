<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members of a project, each with an optional rate that overrides the project
 * default for their own time entries. Detaching a member never touches their
 * time entries: those keep `user_id` and render as a removed member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_project_members', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('user_id');
            $table->bigInteger('rate')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_project_members');
    }
};
