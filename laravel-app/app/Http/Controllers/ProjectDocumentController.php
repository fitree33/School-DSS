<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectDocumentController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,txt', 'max:10240'],
        ]);

        $file = $data['document'];
        $path = $file->store("project-documents/{$project->id}");
        $webhookUrl = config('services.n8n.document_webhook_url');

        $document = ProjectDocument::create([
            'project_id' => $project->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'processing_status' => filled($webhookUrl) ? 'processing' : 'pending',
        ]);

        AuditLog::record('project_document.uploaded', $document, [], [
            'project_id' => $project->id,
            'original_name' => $document->original_name,
            'size' => $document->size,
        ]);

        if (filled($webhookUrl)) {
            try {
                Http::timeout(60)
                    ->attach('data', file_get_contents($file->getRealPath()), $document->original_name)
                    ->post($webhookUrl, [
                        'project_id' => $project->id,
                        'project_name' => $project->name,
                        'document_id' => $document->id,
                    ])
                    ->throw();
            } catch (\Throwable $exception) {
                $document->update([
                    'processing_status' => 'failed',
                    'processing_error' => $exception->getMessage(),
                ]);

                Log::warning('Unable to send project document to n8n.', [
                    'project_id' => $project->id,
                    'document_id' => $document->id,
                    'message' => $exception->getMessage(),
                ]);

                return back()->with(
                    'warning',
                    'อัปโหลดเอกสารแล้ว แต่ยังส่งไปประมวลผลด้วย AI ไม่สำเร็จ'
                );
            }
        }

        return back()->with('success', 'อัปโหลดเอกสารเรียบร้อยแล้ว');
    }
}
