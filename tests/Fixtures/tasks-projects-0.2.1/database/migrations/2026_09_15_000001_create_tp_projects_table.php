<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects belong to a company and optionally to a customer. A project without
 * a customer is internal and never reaches the billing screen.
 *
 * Host key widths are not uniform: companies, users and currencies use
 * `increments` while customers use `bigIncrements`, so each column matches its
 * parent by hand. There are no database-level foreign keys; cascades live in
 * the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_projects', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('name');
            $table->string('identifier', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('colour', 16)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedInteger('currency_id')->nullable();
            $table->bigInteger('default_rate')->nullable();
            $table->unsignedInteger('budget_minutes')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('creator_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_projects');
    }
};
