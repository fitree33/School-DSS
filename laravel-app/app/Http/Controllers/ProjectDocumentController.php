<?php

namespace App\Http\Controllers;

use App\Enums\DocumentVersionCreatedVia;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\Documents\DocumentVersionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProjectDocumentController extends Controller
{
    public function __construct(private readonly DocumentVersionService $versions) {}

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,txt', 'max:10240'],
        ]);

        $file = $data['document'];
        $disk = config('filesystems.default');
        $directory = "project-documents/{$project->id}";
        $path = null;
        $newUploadPath = false;
        $webhookUrl = config('services.n8n.document_webhook_url');

        try {
            $name = $file->hashName();
            $path = $directory.'/'.$name;
            if (Storage::disk($disk)->exists($path)) {
                throw new \RuntimeException('The upload destination is already occupied.');
            }
            // Know the newly generated locator before storage can partially
            // write and throw, so failure compensation can remove that file.
            $newUploadPath = true;
            if ($file->storeAs($directory, $name, $disk) !== $path) {
                throw new \RuntimeException('The uploaded document could not be stored.');
            }
            $document = DB::transaction(function () use ($file, $disk, $path, $project, $request, $webhookUrl) {
                $document = ProjectDocument::create([
                    'project_id' => $project->id,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'storage_disk' => $disk,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                    'checksum' => hash_file('sha256', $file->getRealPath()),
                    'processing_status' => filled($webhookUrl) ? 'processing' : 'pending',
                ]);

                $this->versions->registerInitialVersion($document, DocumentVersionCreatedVia::LegacyUpload, $request->user());
                AuditLog::record('project_document.uploaded', $document, [], [
                    'project_id' => $project->id,
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                ]);

                return $document;
            });
        } catch (\Throwable $exception) {
            // Only compensate the newly generated upload path, never an existing original.
            if ($newUploadPath && is_string($path)) {
                try {
                    if (! ProjectDocument::query()->where('storage_disk', $disk)->where('path', $path)->exists()
                        && ! Storage::disk($disk)->delete($path)) {
                        report(new \RuntimeException('The failed upload could not be cleaned up.'));
                    }
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            report($exception);

            return back()->withErrors(['document' => 'The uploaded document could not be verified and registered.']);
        }

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
