<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_finding_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_request_id')->constrained()->cascadeOnDelete();
            $table->string('rule_family');
            $table->string('directory');
            $table->string('severity');
            // Which score dimension this group fed — makes it possible to
            // explain a score after the fact without re-deriving the mapping.
            $table->string('dimension');
            $table->unsignedInteger('count');
            $table->unsignedInteger('score');
            $table->json('examples');
            $table->json('tools');
            $table->timestamps();

            // Supports later cross-run comparison of the same family in the
            // same directory (spec §6.5, deferred group-level deltas).
            $table->index(['rule_family', 'directory']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_finding_groups');
    }
};
