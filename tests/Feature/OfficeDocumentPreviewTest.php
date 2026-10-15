<?php

namespace Tests\Feature;

use App\Contracts\OfficeDocumentConverter;
use App\Exceptions\OfficeDocumentPreviewException;
use App\Jobs\GenerateOfficeDocumentPreview;
use App\Livewire\Documents\VerificationShow;
use App\Models\AccreditationActivityLog;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\AccreditationReviewSubmission;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\LibreOfficeDocumentConverter;
use App\Services\OfficeDocumentPreviewPathService;
use App\Services\OfficeDocumentPreviewService;
use App\Services\OfficeOpenXmlSecurityValidator;
use Closure;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery\MockInterface;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class OfficeDocumentPreviewTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
        $this->temporaryRoot = storage_path('framework/office-preview-tests/'.Str::uuid());
        config([
            'document-preview.enabled' => true,
            'document-preview.security.network_isolated' => false,
            'document-preview.profile_version' => 'test-profile-v1',
            'document-preview.temporary_root' => $this->temporaryRoot,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);
        parent::tearDown();
    }

    public function test_valid_docx_and_xlsx_cache_miss_dispatch_conversion_jobs(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $docx = $this->officeDocument($requirement, $uploader, 'docx');
        $xlsx = $this->officeDocument($requirement, $uploader, 'xlsx');
        $submission->documents()->attach([$docx->id, $xlsx->id]);
        $this->fakeConverter();
        Queue::fake();

        $this->actingAs($reviewer)
            ->post(route('verifications.documents.office-preview.request', [$submission, $docx]))
            ->assertAccepted()
            ->assertJsonPath('state', 'processing');
        $this->post(route('verifications.documents.office-preview.request', [$submission, $xlsx]))
            ->assertAccepted()
            ->assertJsonPath('state', 'processing');

        Queue::assertPushed(GenerateOfficeDocumentPreview::class, 2);
        Queue::assertPushed(fn (GenerateOfficeDocumentPreview $job): bool => $job->documentId === $docx->id
            && $job->submissionId === $submission->id);
        Queue::assertPushed(fn (GenerateOfficeDocumentPreview $job): bool => $job->documentId === $xlsx->id
            && $job->submissionId === $submission->id);
    }

    public function test_pdf_and_browser_images_never_dispatch_office_converter(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $documents = collect([
            $this->document($requirement, $uploader, 'pdf', $this->pdfContents()),
            $this->document($requirement, $uploader, 'jpeg', 'jpeg-content'),
            $this->document($requirement, $uploader, 'png', 'png-content'),
        ]);
        $submission->documents()->attach($documents->pluck('id')->all());
        $this->fakeConverter();
        Queue::fake();
        $this->actingAs($reviewer);

        foreach ($documents as $document) {
            $this->post(route('verifications.documents.office-preview.request', [$submission, $document]))
                ->assertOk()
                ->assertJsonPath('state', 'unsupported');
        }

        Queue::assertNothingPushed();
    }

    public function test_cache_hit_skips_job_and_cache_key_changes_with_checksum(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        $submission->documents()->attach($document->id);
        $paths = app(OfficeDocumentPreviewPathService::class);
        $originalPath = $paths->preview($document);
        Storage::disk('local')->put($originalPath, $this->pdfContents(), ['visibility' => 'private']);
        $changed = clone $document;
        $changed->sha256 = str_repeat('a', 64);
        $this->assertNotSame($originalPath, $paths->preview($changed));
        $this->fakeConverter();
        Queue::fake();

        $this->actingAs($reviewer)
            ->post(route('verifications.documents.office-preview.request', [$submission, $document]))
            ->assertOk()
            ->assertJsonPath('state', 'ready');

        Queue::assertNothingPushed();
    }

    public function test_guest_outside_reviewer_and_document_outside_snapshot_are_rejected(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        $outsideDocument = $this->officeDocument($requirement, $uploader, 'docx', 'outside.docx');
        $submission->documents()->attach($document->id);
        $this->fakeConverter();

        $this->get(route('verifications.documents.office-preview.status', [$submission, $document]))
            ->assertRedirect(route('login'));

        $outsider = $this->userWithRole('Reviewer');
        $this->actingAs($outsider)
            ->get(route('verifications.documents.office-preview.status', [$submission, $document]))
            ->assertForbidden();

        $this->actingAs($reviewer)
            ->get(route('verifications.documents.office-preview.status', [$submission, $outsideDocument]))
            ->assertNotFound();
    }

    public function test_successful_conversion_preserves_original_workflow_and_publishes_private_inline_pdf(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        $submission->documents()->attach($document->id);
        $originalContents = Storage::disk('local')->get($document->storage_path);
        $originalChecksum = $document->sha256;
        $activityCount = AccreditationActivityLog::count();
        $documentCount = AccreditationDocument::count();
        $this->fakeConverter();

        app(OfficeDocumentPreviewService::class)->generate($document, $submission, $document->sha256);

        $previewPath = app(OfficeDocumentPreviewPathService::class)->preview($document);
        Storage::disk('local')->assertExists($previewPath);
        Storage::disk('public')->assertMissing($previewPath);
        $this->assertSame('private', config('filesystems.disks.local.visibility'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertSame($originalContents, Storage::disk('local')->get($document->storage_path));
        $this->assertSame($originalChecksum, $document->fresh()->sha256);
        $this->assertSame($documentCount, AccreditationDocument::count());
        $this->assertSame($activityCount, AccreditationActivityLog::count());
        $this->assertSame('pending_review', $submission->fresh()->status);
        $this->assertSame('pending_review', $requirement->fresh()->workflow_status);
        $this->assertSame(1, $requirement->fresh()->current_review_revision);
        $this->assertSame([], File::directories($this->temporaryRoot));

        $response = $this->actingAs($reviewer)
            ->get(route('verifications.documents.office-preview.content', [$submission, $document]));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $response->headers->get('content-disposition'));
        $response->assertDontSee($previewPath);
    }

    public function test_macro_external_relationship_and_zip_bomb_are_rejected(): void
    {
        $validator = app(OfficeOpenXmlSecurityValidator::class);

        foreach ([
            $this->ooxmlPath('docx', [
                'word/vbaProject.bin' => 'macro',
            ]),
            $this->ooxmlPath('docx', [
                '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="https://example.test/file" TargetMode="External" Type="test"/></Relationships>',
            ]),
            $this->ooxmlPath('docx', [
                '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="custom/data.bin" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject"/></Relationships>',
            ]),
        ] as $path) {
            try {
                $validator->validate($path, 'docx');
                $this->fail('OOXML berbahaya seharusnya ditolak.');
            } catch (OfficeDocumentPreviewException) {
                $this->assertTrue(true);
            } finally {
                File::delete($path);
            }
        }

        config(['document-preview.ooxml.max_compression_ratio' => 10]);
        $bombPath = $this->ooxmlPath('xlsx', ['xl/sharedStrings.xml' => str_repeat('A', 1024 * 1024)]);
        try {
            $validator->validate($bombPath, 'xlsx');
            $this->fail('ZIP bomb seharusnya ditolak.');
        } catch (OfficeDocumentPreviewException) {
            $this->assertTrue(true);
        } finally {
            File::delete($bombPath);
        }

        $contentTypesBomb = $this->ooxmlPath('xlsx', [
            '[Content_Types].xml' => str_repeat('A', 1024 * 1024),
        ]);
        try {
            $validator->validate($contentTypesBomb, 'xlsx');
            $this->fail('ZIP bomb pada content types seharusnya ditolak sebelum dibaca.');
        } catch (OfficeDocumentPreviewException) {
            $this->assertTrue(true);
        } finally {
            File::delete($contentTypesBomb);
        }
    }

    public function test_stale_processing_marker_is_redispatched_but_fresh_marker_is_not(): void
    {
        [$submission, , $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        $submission->documents()->attach($document->id);
        $paths = app(OfficeDocumentPreviewPathService::class);
        $this->fakeConverter();
        Queue::fake();

        Storage::disk('local')->put($paths->status($document), json_encode([
            'document_id' => $document->id,
            'sha256' => strtolower($document->sha256),
            'profile_version' => config('document-preview.profile_version'),
            'state' => 'processing',
            'updated_at' => now()->subMinutes(10)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        app(OfficeDocumentPreviewService::class)->request($document, $submission);
        Queue::assertPushed(GenerateOfficeDocumentPreview::class, 1);

        app(OfficeDocumentPreviewService::class)->request($document, $submission);
        Queue::assertPushed(GenerateOfficeDocumentPreview::class, 1);
    }

    public function test_truncated_cached_pdf_is_rejected(): void
    {
        [, , $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'xlsx');
        Storage::disk('local')->put(
            app(OfficeDocumentPreviewPathService::class)->preview($document),
            "%PDF-1.4\n".str_repeat('A', 100),
        );
        $this->fakeConverter();

        $this->assertSame('failed', app(OfficeDocumentPreviewService::class)->status($document)['state']);
    }

    public function test_network_isolation_is_required_by_default(): void
    {
        $this->assertTrue(config('document-preview.security.require_network_isolation'));
        config([
            'document-preview.security.network_isolated' => false,
            'document-preview.enabled' => true,
        ]);
        $this->app->forgetInstance(OfficeDocumentConverter::class);
        $this->app->forgetInstance(OfficeDocumentPreviewService::class);

        $availability = app(OfficeDocumentConverter::class)->availability();

        $this->assertFalse($availability['available']);
        $this->assertSame('Network isolation converter belum dikonfigurasi.', $availability['reason']);
    }

    public function test_libreoffice_child_environment_disables_opencl_and_removes_inherited_secrets(): void
    {
        $secretName = 'OFFICE_PREVIEW_TEST_SECRET';
        $previousSecret = getenv($secretName);
        putenv($secretName.'=must-not-reach-child');

        try {
            $method = new ReflectionMethod(LibreOfficeDocumentConverter::class, 'sanitizedEnvironment');
            $environment = $method->invoke(
                app(LibreOfficeDocumentConverter::class),
                'C:\\preview-home',
                'C:\\preview-temp',
                'C:\\preview-config',
                'C:\\preview-cache',
                'C:\\preview-runtime',
            );

            $this->assertSame('1', $environment['SAL_DISABLE_OPENCL']);
            $this->assertSame('svp', $environment['SAL_USE_VCLPLUGIN']);
            $this->assertFalse($environment[$secretName]);
            $this->assertSame('C:\\preview-temp', $environment['TEMP']);
        } finally {
            $previousSecret === false
                ? putenv($secretName)
                : putenv($secretName.'='.$previousSecret);
        }
    }

    public function test_cleanup_removes_normal_nested_workspace(): void
    {
        $workspace = $this->temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid();
        File::ensureDirectoryExists($workspace.DIRECTORY_SEPARATOR.'nested');
        File::put($workspace.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'preview.tmp', 'temporary');

        $this->cleanupWorkspace($workspace);

        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function test_cleanup_normalizes_read_only_windows_nodes_before_removal(): void
    {
        $workspace = $this->temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid();
        $directory = $workspace.DIRECTORY_SEPARATOR.'Documents';
        $file = $directory.DIRECTORY_SEPARATOR.'registrymodifications.xcu';
        File::ensureDirectoryExists($directory);
        File::put($file, 'temporary');
        chmod($file, 0444);
        chmod($directory, 0444);

        $this->cleanupWorkspace($workspace);

        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function test_cleanup_stops_at_reparse_point_without_touching_target_after_partial_deletion(): void
    {
        $workspace = $this->temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid();
        $outsideRoot = storage_path('framework/office-preview-link-target-'.Str::uuid());
        $link = $workspace.DIRECTORY_SEPARATOR.'z-linked-directory';
        File::ensureDirectoryExists($workspace);
        File::ensureDirectoryExists($outsideRoot);
        File::put($workspace.DIRECTORY_SEPARATOR.'a-removable.tmp', 'remove');
        File::put($outsideRoot.DIRECTORY_SEPARATOR.'sentinel.txt', 'keep');

        if (! $this->createDirectoryLink($outsideRoot, $link)) {
            File::deleteDirectory($outsideRoot);
            File::deleteDirectory($workspace);
            $this->markTestSkipped('A directory link could not be created on this platform.');
        }

        Log::spy();

        try {
            $this->cleanupWorkspace($workspace);

            $this->assertFileDoesNotExist($workspace.DIRECTORY_SEPARATOR.'a-removable.tmp');
            $this->assertFileExists($outsideRoot.DIRECTORY_SEPARATOR.'sentinel.txt');
            $this->assertDirectoryExists($workspace);
            Log::shouldHaveReceived('warning')->once()->with(
                'Office preview temporary workspace cleanup did not complete.',
                \Mockery::on(fn (array $context): bool => $context['reason'] === 'delete_error'
                    && $context['exception'] === \RuntimeException::class
                    && ! array_key_exists('path', $context)
                    && strlen($context['workspace_reference']) === 16),
            );
        } finally {
            PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
            File::deleteDirectory($outsideRoot);
            File::deleteDirectory($workspace);
        }
    }

    public function test_cleanup_refuses_uuid_workspace_outside_trusted_temporary_root(): void
    {
        $outsideRoot = storage_path('framework/office-preview-outside-'.Str::uuid());
        $outsideWorkspace = $outsideRoot.DIRECTORY_SEPARATOR.Str::uuid();
        File::ensureDirectoryExists($outsideWorkspace);
        Log::spy();

        try {
            $this->cleanupWorkspace($outsideWorkspace);

            $this->assertDirectoryExists($outsideWorkspace);
            Log::shouldHaveReceived('warning')->once()->with(
                'Office preview temporary workspace cleanup did not complete.',
                \Mockery::on(fn (array $context): bool => $context['reason'] === 'boundary_check_failed'),
            );
        } finally {
            File::deleteDirectory($outsideRoot);
        }
    }

    public function test_cleanup_refuses_reparse_point_used_as_workspace_root(): void
    {
        $target = $this->temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid();
        $workspace = $this->temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid();
        File::ensureDirectoryExists($target);
        File::put($target.DIRECTORY_SEPARATOR.'sentinel.txt', 'keep');

        if (! $this->createDirectoryLink($target, $workspace)) {
            File::deleteDirectory($target);
            $this->markTestSkipped('A directory link could not be created on this platform.');
        }

        Log::spy();

        try {
            $this->cleanupWorkspace($workspace);

            $this->assertFileExists($target.DIRECTORY_SEPARATOR.'sentinel.txt');
            $this->assertNotFalse(realpath($workspace));
            Log::shouldHaveReceived('warning')->once()->with(
                'Office preview temporary workspace cleanup did not complete.',
                \Mockery::on(fn (array $context): bool => $context['reason'] === 'boundary_check_failed'),
            );
        } finally {
            PHP_OS_FAMILY === 'Windows' ? @rmdir($workspace) : @unlink($workspace);
            File::deleteDirectory($target);
        }
    }

    public function test_converter_timeout_failure_and_corrupt_pdf_never_publish_partial_cache(): void
    {
        [$submission, , $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        $submission->documents()->attach($document->id);

        foreach ([
            new OfficeDocumentPreviewException('Konversi Office melewati batas waktu.', true),
            new OfficeDocumentPreviewException('LibreOffice gagal mengonversi dokumen.', true),
        ] as $failure) {
            $this->fakeConverter(fn () => throw $failure);
            try {
                app(OfficeDocumentPreviewService::class)->generate($document, $submission, $document->sha256);
                $this->fail('Converter failure seharusnya diteruskan ke queue retry.');
            } catch (OfficeDocumentPreviewException $exception) {
                $this->assertTrue($exception->retryable);
            }
            Storage::disk('local')->assertMissing(app(OfficeDocumentPreviewPathService::class)->preview($document));
            $this->app->forgetInstance(OfficeDocumentPreviewService::class);
        }

        $this->fakeConverter(function (string $input, string $outputDirectory): string {
            File::ensureDirectoryExists($outputDirectory);
            $path = $outputDirectory.DIRECTORY_SEPARATOR.pathinfo($input, PATHINFO_FILENAME).'.pdf';
            File::put($path, 'not-a-pdf');

            return $path;
        });
        try {
            app(OfficeDocumentPreviewService::class)->generate($document, $submission, $document->sha256);
            $this->fail('Output PDF rusak seharusnya ditolak.');
        } catch (OfficeDocumentPreviewException) {
            $this->assertTrue(true);
        }
        Storage::disk('local')->assertMissing(app(OfficeDocumentPreviewPathService::class)->preview($document));
    }

    public function test_repeated_requests_dispatch_only_one_job(): void
    {
        [$submission, , $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'xlsx');
        $submission->documents()->attach($document->id);
        $this->fakeConverter();
        Queue::fake();
        $service = app(OfficeDocumentPreviewService::class);

        $service->request($document, $submission);
        $service->request($document, $submission);

        Queue::assertPushed(GenerateOfficeDocumentPreview::class, 1);
    }

    public function test_verification_page_lists_originals_without_dispatching_office_preview(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $docx = $this->officeDocument($requirement, $uploader, 'docx');
        $pdf = $this->document($requirement, $uploader, 'pdf', $this->pdfContents());
        $submission->documents()->attach([$docx->id, $pdf->id]);
        Queue::fake();
        $this->actingAs($reviewer);

        Livewire::test(VerificationShow::class, ['submission' => $submission])
            ->set('decision', 'need_revision')
            ->set('reviewNote', 'Perbaiki tabel pada halaman kedua.')
            ->assertSet('decision', 'need_revision')
            ->assertSet('reviewNote', 'Perbaiki tabel pada halaman kedua.')
            ->assertSee($docx->original_name)
            ->assertSee($pdf->original_name)
            ->assertDontSee(route('verifications.documents.open', [$submission, $docx]), false)
            ->assertSee(route('verifications.documents.open', [$submission, $pdf]), false)
            ->assertSee(route('verifications.documents.download', [$submission, $docx]), false)
            ->assertSee(route('verifications.documents.download', [$submission, $pdf]), false)
            ->assertDontSee('Preview Office belum tersedia')
            ->assertDontSee('Converter belum diaktifkan.');

        Queue::assertNothingPushed();
    }

    public function test_scoped_original_endpoint_only_opens_browser_safe_formats_inline(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $documents = collect([
            $this->document($requirement, $uploader, 'pdf', $this->pdfContents()),
            $this->document($requirement, $uploader, 'jpeg', 'jpeg-content'),
            $this->document($requirement, $uploader, 'png', 'png-content'),
            $this->officeDocument($requirement, $uploader, 'docx'),
            $this->officeDocument($requirement, $uploader, 'xlsx'),
        ]);
        $submission->documents()->attach($documents->pluck('id')->all());
        $this->actingAs($reviewer);

        foreach ($documents->take(3) as $document) {
            $response = $this->get(route('verifications.documents.open', [$submission, $document]));

            $response->assertOk()->assertHeader('x-content-type-options', 'nosniff');
            $this->assertStringStartsWith('inline;', (string) $response->headers->get('content-disposition'));
        }

        foreach ($documents->slice(3) as $document) {
            $response = $this->get(route('verifications.documents.open', [$submission, $document]));

            $response->assertOk()->assertHeader('x-content-type-options', 'nosniff');
            $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'));
        }
    }

    public function test_disabled_converter_degrades_without_queue_or_server_error(): void
    {
        [$submission, $reviewer, $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        $submission->documents()->attach($document->id);
        config(['document-preview.enabled' => false]);
        $this->app->forgetInstance(OfficeDocumentPreviewService::class);
        Queue::fake();

        $this->actingAs($reviewer)
            ->get(route('verifications.show', $submission))
            ->assertOk()
            ->assertDontSee('Buka Tab Baru')
            ->assertSee('Download Original')
            ->assertDontSee('Preview Office belum tersedia')
            ->assertDontSee('Converter belum diaktifkan.')
            ->assertSee(route('verifications.documents.download', [$submission, $document]), false);
        $officeOpen = $this->get(route('verifications.documents.open', [$submission, $document]));
        $officeOpen->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $officeOpen->headers->get('content-disposition'));

        Queue::assertNothingPushed();
    }

    public function test_disabled_converter_does_not_serve_an_existing_office_cache(): void
    {
        [, , $requirement, $uploader] = $this->context();
        $document = $this->officeDocument($requirement, $uploader, 'docx');
        Storage::disk('local')->put(
            app(OfficeDocumentPreviewPathService::class)->preview($document),
            $this->pdfContents(),
        );
        config(['document-preview.enabled' => false]);

        $this->assertSame('unavailable', app(OfficeDocumentPreviewService::class)->status($document)['state']);
    }

    private function fakeConverter(?Closure $conversion = null): MockInterface
    {
        $conversion ??= function (string $input, string $outputDirectory): string {
            File::ensureDirectoryExists($outputDirectory);
            $path = $outputDirectory.DIRECTORY_SEPARATOR.pathinfo($input, PATHINFO_FILENAME).'.pdf';
            File::put($path, $this->pdfContents());

            return $path;
        };
        $converter = $this->mock(OfficeDocumentConverter::class);
        $converter->shouldReceive('availability')->andReturn([
            'available' => true,
            'reason' => 'Siap',
        ]);
        $converter->shouldReceive('convert')->andReturnUsing($conversion);
        $this->app->forgetInstance(OfficeDocumentPreviewService::class);

        return $converter;
    }

    private function cleanupWorkspace(string $workspace): void
    {
        $method = new ReflectionMethod(OfficeDocumentPreviewService::class, 'cleanupWorkspace');
        $method->invoke(app(OfficeDocumentPreviewService::class), $workspace);
    }

    private function createDirectoryLink(string $target, string $link): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return symlink($target, $link);
        }

        $link = str_replace('/', '\\', $link);
        $target = str_replace('/', '\\', $target);
        if (preg_match('/\s/', $link.$target) === 1) {
            return false;
        }

        $process = new Process(['cmd.exe', '/d', '/c', "mklink /J {$link} {$target}"]);
        $process->run();

        return $process->isSuccessful() && realpath($link) !== false;
    }

    private function context(): array
    {
        $suffix = strtoupper(Str::random(8));
        $program = StudyProgram::create([
            'code' => 'PRODI-'.$suffix,
            'name' => 'Bioteknologi '.$suffix,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => 'PROJECT-'.$suffix,
            'name' => 'Akreditasi '.$suffix,
            'study_program_id' => $program->id,
            'final_deadline' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $criterion = AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => 'A',
            'name' => 'Visi, Misi, Tujuan dan Strategi',
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => 1,
        ]);
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => 2,
            'position' => 2,
            'title' => 'Statuta Perguruan Tinggi',
        ]);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => 1,
            'title' => 'Dokumen Statuta Perguruan Tinggi',
            'is_required' => true,
            'workflow_status' => 'pending_review',
            'current_review_revision' => 1,
        ]);
        $uploader = $this->userWithRole('PIC Kriteria');
        $reviewer = $this->userWithRole('Reviewer');
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $reviewer->id,
            'assignment_role' => 'reviewer',
            'is_active' => true,
        ]);
        $submission = AccreditationReviewSubmission::create([
            'requirement_id' => $requirement->id,
            'submitted_by' => $uploader->id,
            'revision_number' => 1,
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);

        return [$submission, $reviewer, $requirement, $uploader];
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role.' '.Str::random(5),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('ValidPassword!123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function officeDocument(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $extension,
        ?string $name = null,
    ): AccreditationDocument {
        $path = $this->ooxmlPath($extension);
        $contents = File::get($path);
        File::delete($path);

        return $this->document($requirement, $uploader, $extension, $contents, $name);
    }

    private function document(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $extension,
        string $contents,
        ?string $name = null,
    ): AccreditationDocument {
        $uuid = (string) Str::uuid();
        $path = "accreditation/{$requirement->id}/{$uuid}.{$extension}";
        Storage::disk('local')->put($path, $contents, ['visibility' => 'private']);

        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => $name ?? "evidence.{$extension}",
            'disk' => 'local',
            'storage_path' => $path,
            'storage_uuid' => $uuid,
            'mime_type' => match ($extension) {
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf' => 'application/pdf',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream',
            },
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
    }

    private function ooxmlPath(string $extension, array $extraEntries = []): string
    {
        File::ensureDirectoryExists($this->temporaryRoot);
        $path = $this->temporaryRoot.DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');

        if ($extension === 'docx') {
            $archive->addFromString('word/document.xml', '<?xml version="1.0"?><document xmlns="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><body><p>Preview test</p></body></document>');
        } else {
            $archive->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Visible 1" sheetId="1" state="visible"/><sheet name="Hidden" sheetId="2" state="hidden"/><sheet name="Visible 2" sheetId="3" state="visible"/></sheets></workbook>');
        }

        foreach ($extraEntries as $entry => $contents) {
            $archive->addFromString($entry, $contents);
        }

        $archive->close();

        return $path;
    }

    private function pdfContents(): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog >>\nendobj\n"
            ."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            ."trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n64\n%%EOF\n";
    }
}
