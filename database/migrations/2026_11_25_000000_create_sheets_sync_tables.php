<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nje rresht per cdo file Google Sheet qe duhet sinkronizuar.
        Schema::create('sync_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('spreadsheet_id', 128)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Nje rresht per cdo cift (tab -> tabela ne databaze). Nje tab mund te shkoje ne disa tabela.
        Schema::create('sync_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sync_sources')->cascadeOnDelete();
            $table->string('tab_name', 100);
            $table->string('target_table', 40);
            $table->unsignedSmallInteger('header_row')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['source_id', 'tab_name', 'target_table'], 'sync_mappings_unique_target');
        });

        // Cila kolone nga sheet shkon ne cilen kolone te databazes dhe si konvertohet.
        Schema::create('sync_mapping_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapping_id')->constrained('sync_mappings')->cascadeOnDelete();
            $table->string('source_column');
            $table->string('target_column', 63);
            $table->string('data_type', 20)->default('string');
            $table->timestamps();

            $table->unique(['mapping_id', 'target_column']);
        });

        // Historia e te gjitha perpjekjeve te sinkronizimit.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_mapping_id')->constrained('sync_mappings')->cascadeOnDelete();
            $table->string('status', 20)->default('running'); // running | success | warning | failed
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_written')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('columns_added')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['sync_mapping_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('sync_mapping_columns');
        Schema::dropIfExists('sync_mappings');
        Schema::dropIfExists('sync_sources');
    }
};