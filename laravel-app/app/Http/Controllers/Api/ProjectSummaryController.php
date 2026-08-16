<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Http\Request;

class ProjectSummaryController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $token = config('services.n8n.webhook_token');

        if (blank($token) || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'summary' => ['required', 'string'],
            'document_id' => ['nullable', 'integer'],
        ]);

        $oldSummary = $project->ai_summary;
        $project->update(['ai_summary' => $data['summary'], 'ai_summarized_at' => now()]);

        $documentQuery = ProjectDocument::query()
            ->where('project_id', $project->id)
            ->when(
                $data['document_id'] ?? null,
                fn ($query, $documentId) => $query->whereKey($documentId),
                fn ($query) => $query->where('processing_status', 'processing')->latest()
            );

        $documentQuery->first()?->update([
            'processing_status' => 'completed',
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        AuditLog::record('project.ai_summary_updated', $project, [
            'ai_summary' => $oldSummary,
        ], [
            'ai_summary' => $project->ai_summary,
        ]);

        return response()->json(['success' => true, 'project_id' => $project->id]);
    }
}
