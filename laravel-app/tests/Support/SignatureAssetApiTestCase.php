<?php

namespace Tests\Support;

use App\Models\Role;
use App\Models\SignatureAsset;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\TestCase;

abstract class SignatureAssetApiTestCase extends TestCase
{
    use DatabaseMigrations, GuardsSignatureAssetMysql {
        GuardsSignatureAssetMysql::beforeRefreshingDatabase insteadof DatabaseMigrations;
    }
    use UsesPrivateSignatureStorage;

    /** These mutations require real top-level transactions, including commit-failure cases. */
    public function runDatabaseMigrations(): void
    {
        $this->guardSignatureAssetDatabase();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            // Populated immutable registries deliberately forbid migrate:rollback.
            // The next guarded test setup recreates only the disposable database.
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
            DB::disconnect();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateSignatureStorage();
        $this->seed(AuthorizationSeeder::class);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->tearDownPrivateSignatureStorage();
        } finally {
            parent::tearDown();
        }
    }

    protected function assetUser(array $overrides = []): User
    {
        return User::factory()->create(array_replace([
            'role_id' => Role::query()->where('code', 'teacher')->firstOrFail()->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function assetUpload(?string $bytes = null): UploadedFile
    {
        // Real private-path and ACL validation remains active for framework test uploads.
        return new UploadedFile($this->signatureUploadFixture($bytes ?? SignaturePngFixture::rgba()), 'signature.png', 'image/png', UPLOAD_ERR_OK, true);
    }

    protected function assetPath(SignatureAsset $asset): string
    {
        return config('signature_assets.storage_root').DIRECTORY_SEPARATOR.$asset->storage_key;
    }

    /** @return list<string> */
    protected function signatureStoredPngs(): array
    {
        $paths = glob(config('signature_assets.storage_root').DIRECTORY_SEPARATOR.'*.png');
        $this->assertIsArray($paths);
        sort($paths);

        return $paths;
    }

    protected function assertSignatureTemporaryEmpty(): void
    {
        $temporary = config('signature_assets.temporary_directory');
        if (! is_dir($temporary)) {
            return;
        }
        $entries = array_values(array_diff(scandir($temporary), ['.', '..']));
        $this->assertSame([], $entries);
    }

    protected function assertSafeAssetMetadata(array $data, SignatureAsset $asset): void
    {
        $this->assertSame([
            'public_id', 'size_bytes', 'width', 'height', 'mime_type', 'normalization_version',
            'status', 'created_at', 'retired_at', 'eligible_for_signing',
        ], array_keys($data));
        $this->assertSame($asset->public_id, $data['public_id']);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach ([$asset->storage_key, $asset->sha256, $this->signatureTestDirectory] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
