<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Projects\Actions\AddProjectMemberAction;
use Modules\Projects\Models\Project;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
    Storage::fake('private');
});

function validPdfUpload(string $name, int $kilobytes = 1): UploadedFile
{
    $pdf = "%PDF-1.7\\n1 0 obj\\n<< /Type /Catalog >>\\nendobj\\n%%EOF\\n";
    $content = str_pad($pdf, $kilobytes * 1024, ' ');

    return UploadedFile::fake()->createWithContent($name, $content);
}

it('uploads project files to private storage and returns safe metadata', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $response = $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/projects/'.$project->getKey().'/files', [
            'file' => validPdfUpload('Design mockups.pdf', 120),
        ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Design mockups.pdf')
        ->assertJsonPath('data.mime_type', 'application/pdf')
        ->assertJsonPath('data.size_bytes', 120 * 1024)
        ->assertJsonPath('data.attached_at', fn ($value) => is_string($value))
        ->assertJsonPath('data.download_url', fn ($value) => is_string($value))
        ->assertJsonMissingPath('data.storage_disk')
        ->assertJsonMissingPath('data.storage_path');

    $file = File::query()->findOrFail($response->json('data.id'));
    Storage::disk('private')->assertExists($file->storage_path);
    expect($file->projects()->whereKey($project->getKey())->exists())->toBeTrue();
});

it('returns recent project files and paginated all files', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    for ($index = 1; $index <= 5; $index++) {
        $file = File::factory()->create(['original_name' => 'Design-'.$index.'.pdf']);
        $project->files()->attach($file->getKey(), [
            'attached_by_user_id' => $manager->getKey(),
            'attached_at' => now()->addSeconds($index),
        ]);
    }

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/files?per_page=2');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data.recent')
        ->assertJsonPath('data.recent.0.name', 'Design-5.pdf')
        ->assertJsonPath('data.recent.1.name', 'Design-4.pdf')
        ->assertJsonPath('data.recent.2.name', 'Design-3.pdf')
        ->assertJsonCount(2, 'data.files')
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.total', 5)
        ->assertJsonPath('meta.pagination.last_page', 3);
});

it('restricts file listing and download to users with access through the project', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);
    $outsider = User::factory()->create();
    $outsider->assignRole('Team Member');

    $uploadResponse = $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/projects/'.$project->getKey().'/files', [
            'file' => validPdfUpload('Requirements.pdf', 64),
        ], ['Accept' => 'application/json']);

    $fileId = $uploadResponse->assertCreated()->json('data.id');
    $downloadPath = '/api/v1/files/'.$fileId.'/download';

    $this->actingAs($member, 'sanctum')->get($downloadPath)->assertOk();
    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/files')
        ->assertForbidden();
    $this->actingAs($outsider, 'sanctum')->get($downloadPath)->assertForbidden();
});

it('rejects unsupported file types', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/projects/'.$project->getKey().'/files', [
            'file' => UploadedFile::fake()->create('malicious.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(File::query()->count())->toBe(0);
});


it('requires an explicit organization-wide permission to bypass project file scope', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');

    $unattachedFile = File::factory()->create();

    expect($administrator->can('files.view.all'))->toBeTrue()
        ->and($manager->can('files.view.all'))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('view', $unattachedFile))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('view', $unattachedFile))->toBeFalse();
});


it('rejects common image upload extensions', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $images = [
        ['photo.png', 'image/png'],
        ['photo.jpg', 'image/jpeg'],
        ['photo.jpeg', 'image/jpeg'],
        ['photo.gif', 'image/gif'],
        ['photo.webp', 'image/webp'],
        ['photo.svg', 'image/svg+xml'],
        ['photo.bmp', 'image/bmp'],
    ];

    foreach ($images as [$name, $mimeType]) {
        $this->actingAs($manager, 'sanctum')
            ->post('/api/v1/projects/'.$project->getKey().'/files', [
                'file' => UploadedFile::fake()->create($name, 10, $mimeType),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    expect(File::query()->count())->toBe(0);
});


it('rejects an image payload even when the client renames it to an allowed document extension', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ioAAAAASUVORK5CYII=', true);
    expect($pngBytes)->toBeString();

    $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/projects/'.$project->getKey().'/files', [
            'file' => UploadedFile::fake()->createWithContent('disguised.pdf', $pngBytes),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});



it('returns a JSON 401 for unauthenticated file downloads', function () {
    $this->get('/api/v1/files/1/download')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});


it('uploads and downloads a valid XLSX document with the correct MIME type', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $xlsxBytes = base64_decode(
        'UEsDBBQAAAAIAM1zeV2UlBQNyQAAAAsBAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbF2PwU7DMAyGXyXyFTUpOyCE2u6AxJFxGA9gEreNmthREsb29qTjxsmyrO///A/HawzqQrl44REedQ+K2IrzvIzweX7rnkGViuwwCNMINypwnIbzLVFRjeUywlprejGm2JUiFi2JuF1myRFrW/NiEtoNFzKHvn8yVrgS167uGTANp6bP3pH6wFzfMTaLuQbzI3n7Etl0ywL1+gft3hEwpeAt1vazubD7Z+xknr0lJ/Y7NkSXlAldWYlqDPo+dUTPD3uwmQZzLzP9AlBLAwQUAAAACADNc3ldsPIANYAAAACXAAAADwAAAHhsL3dvcmtib29rLnhtbDWMSw7CMAwFrxJ5Tx1YIISSdMcJ4AChNU3Vxo7iiM/tqZBYvhnNc/07r+ZJVWdhD/vOgiEeZJx58nC7XnYnMNoij3EVJg8fUuiDe0ld7iKL2XJWD6m1ckbUIVGO2kkh3sxDao5tm3VCLZXiqImo5RUP1h4xx5khuB9TDA7/r+ELUEsBAhQDFAAAAAgAzXN5XZSUFA3JAAAACwEAABMAAAAAAAAAAAAAAIABAAAAAFtDb250ZW50X1R5cGVzXS54bWxQSwECFAMUAAAACADNc3ldsPIANYAAAACXAAAADwAAAAAAAAAAAAAAgAH6AAAAeGwvd29ya2Jvb2sueG1sUEsFBgAAAAACAAIAfgAAAKcBAAAAAA==',
        true,
    );

    expect($xlsxBytes)->toBeString();

    $uploadResponse = $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/projects/'.$project->getKey().'/files', [
            'file' => UploadedFile::fake()->createWithContent(
                'selected-orders-2026-10-05-07-48-46.xlsx',
                $xlsxBytes,
            ),
        ], ['Accept' => 'application/json']);

    $uploadResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'selected-orders-2026-10-05-07-48-46.xlsx')
        ->assertJsonPath(
            'data.mime_type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

    $file = File::query()->findOrFail($uploadResponse->json('data.id'));
    Storage::disk('private')->assertExists($file->storage_path);
    expect(pathinfo($file->storage_path, PATHINFO_EXTENSION))->toBe('xlsx')
        ->and($file->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $downloadResponse = $this->actingAs($manager, 'sanctum')
        ->get('/api/v1/files/'.$file->getKey().'/download');

    $downloadResponse->assertOk()
        ->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        )
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'private, no-store, max-age=0');

    expect($downloadResponse->headers->get('Content-Disposition'))
        ->toContain('selected-orders-2026-10-05-07-48-46.xlsx');
});
