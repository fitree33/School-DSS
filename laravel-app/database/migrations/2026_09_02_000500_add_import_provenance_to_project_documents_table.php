<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->string('storage_disk', 50)->nullable()->after('path');
            $table->unsignedBigInteger('source_import_id')->nullable()->after('project_id');
            $table->unique('source_import_id');
            $table->foreign('source_import_id')
                ->references('id')
                ->on('document_imports')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropForeign(['source_import_id']);
            $table->dropUnique(['source_import_id']);
            $table->dropColumn(['source_import_id', 'storage_disk']);
        });
    }
};
