<?php

namespace Tests\Feature\Api\V2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class ImportedOriginalSessionDownloadTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    private const ORIGIN = 'http://127.0.0.1:8015';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPhaseFiveImports();
        Storage::fake('project-imports');
        Storage::fake('original-download-sessions');
        Queue::fake();
        config()->set([
            'sanctum.stateful' => ['127.0.0.1:8015'],
            'session.driver' => 'file',
            'session.files' => Storage::disk('original-download-sessions')->path(''),
            'session.cookie' => 'original_download_session',
            'session.domain' => null,
            'session.secure' => false,
        ]);
    }

    public function test_project_detail_download_uses_the_real_spa_session_cookie_and_preserves_original_bytes(): void
    {
        $owner = $this->phaseFiveUser(overrides: ['password' => 'password']);
        [$import, , $revision] = $this->createReviewableImport($owner, importOverrides: ['original_name' => 'text-project.pdf']);
        $original = Storage::disk($import->storage_disk)->get($import->storage_path);
        $cookies = $this->loginCookies($owner);

        $projectId = $this->sessionRequest('POST', "/api/v2/imports/{$import->public_id}/confirm", $cookies, [
            'preview_revision_id' => $revision->id,
            'idempotency_key' => 'session-download-confirm',
        ])->assertCreated()->json('data.project.id');
        $detail = $this->sessionRequest('GET', "/api/v2/projects/{$projectId}", $cookies)
            ->assertOk()
            ->assertJsonPath('data.documents.0.original_name', 'text-project.pdf');
        $downloadUrl = $detail->json('data.documents.0.download_url');
        $this->assertSame("/api/v2/imports/{$import->public_id}/original", $downloadUrl);

        // A noreferrer navigation sends the cookie but cannot activate Sanctum's session middleware.
        $this->sessionRequest('GET', $downloadUrl, $cookies, frontend: false)
            ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        // A same-origin SPA request retains Referer and reads the encrypted session cookie.
        $download = $this->sessionRequest('GET', $downloadUrl, $cookies)
            ->assertOk()
            ->assertDownload('text-project.pdf')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertStreamedContent($original);
        $this->assertSame($import->sha256, hash('sha256', $download->streamedContent()));
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_contents', 1);
        $this->assertSame($original, Storage::disk($import->storage_disk)->get($import->storage_path));
    }

    public function test_spa_original_download_still_rejects_missing_session_and_another_authenticated_user(): void
    {
        $owner = $this->phaseFiveUser(overrides: ['password' => 'password']);
        $other = $this->phaseFiveUser(overrides: ['password' => 'password']);
        [$import] = $this->createReviewableImport($owner);
        $url = "/api/v2/imports/{$import->public_id}/original";

        $this->sessionRequest('GET', $url)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->sessionRequest('GET', $url, $this->loginCookies($other))->assertForbidden();
        $this->sessionRequest('GET', $url, $this->loginCookies($owner))->assertOk()
            ->assertStreamedContent(Storage::disk($import->storage_disk)->get($import->storage_path));
        $this->sessionRequest('GET', $url)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->assertNoCanonicalImportWrites();
    }

    /** @return array<string, string> */
    private function loginCookies(User $user): array
    {
        $response = $this->sessionRequest('POST', '/api/v2/auth/login', data: [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());

        return [$cookie->getName() => $cookie->getValue()];
    }

    /**
     * Clear request-local auth/session objects so only the encrypted cookie can authenticate.
     * File-backed sessions persist between requests, just as in the isolated QA environment.
     */
    private function sessionRequest(string $method, string $url, array $cookies = [], array $data = [], bool $frontend = true): TestResponse
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app()->forgetInstance(StartSession::class);
        $headers = ['HTTP_ACCEPT' => 'application/json'];
        if ($frontend) {
            $headers['HTTP_REFERER'] = self::ORIGIN.'/app/projects/1';
            $headers['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }

        return $this->call($method, self::ORIGIN.$url, $data, $cookies, [], $headers);
    }
}
