<?php

namespace Tests\Feature;

use App\Models\AccreditationCriterion;
use App\Models\AccreditationCriterionAssignment;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\AccreditationDocumentPathService;
use App\Services\AccreditationDocumentUploadService;
use Database\Seeders\AccreditationRolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AccreditationDocumentPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccreditationRolePermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_uploaded_file_uses_complete_private_path_and_persisted_revision(): void
    {
        [$program, $project, $criterion, $item, $requirement] = $this->structure(
            programName: 'Bioteknologi Terapan',
            projectCode: 'PROJECT-ALPHA-2027',
            criterionCode: 'C',
            itemNumber: 7,
            requirementNumber: 3,
            workflowStatus: 'need_revision',
            currentRevision: 4,
        );
        $pic = $this->picFor($criterion);
        $contents = $this->pdfContents('complete-path');

        $document = $this->upload($requirement, $pic, [$this->pdf('source-name.pdf', $contents)])->sole();
        $expectedDirectory = implode('/', [
            'accreditation/study-programs',
            "bioteknologi-terapan--id-{$program->id}",
            'projects',
            "PROJECT-ALPHA-2027--id-{$project->id}",
            'criteria/C/items',
            "C.7--id-{$item->id}",
            'requirements',
            "R3--id-{$requirement->id}",
            'revisions/v5',
        ]);

        $this->assertSame($expectedDirectory.'/'.$document->storage_uuid.'.pdf', $document->storage_path);
        $this->assertStringNotContainsString('source-name', $document->storage_path);
        $this->assertSame(5, $document->revision_number);
        $this->assertSame(hash('sha256', $contents), $document->sha256);
        $this->assertDatabaseHas('accreditation_documents', [
            'id' => $document->id,
            'storage_path' => $document->storage_path,
            'revision_number' => 5,
        ]);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame('private', config('filesystems.disks.local.visibility'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        Storage::disk('public')->assertMissing($document->storage_path);
    }

    public function test_study_program_project_and_requirement_directories_are_isolated(): void
    {
        [$programOne, , $criterionOne, $itemOne, $requirementOne] = $this->structure(
            programName: 'Bioteknologi',
            projectCode: 'PROJECT-ONE',
            criterionCode: 'B',
        );
        [, , $criterionTwo, , $requirementTwo] = $this->structure(
            programName: 'Farmasi',
            projectCode: 'PROJECT-TWO',
            criterionCode: 'B',
        );
        [, , $criterionThree, , $requirementThree] = $this->structure(
            programName: 'Ignored because program exists',
            projectCode: 'PROJECT-THREE',
            criterionCode: 'B',
            studyProgram: $programOne,
        );
        $secondRequirement = AccreditationDocumentRequirement::create([
            'item_id' => $itemOne->id,
            'number' => 2,
            'title' => 'Rincian Kedua',
            'is_required' => true,
            'workflow_status' => 'not_started',
            'current_review_revision' => 0,
        ]);
        $pic = $this->userWithRole('PIC Kriteria');

        foreach ([$criterionOne, $criterionTwo, $criterionThree] as $criterion) {
            $this->assignment($criterion, $pic);
        }

        $paths = [
            $this->upload($requirementOne, $pic, [$this->pdf('one.pdf')])->sole()->storage_path,
            $this->upload($requirementTwo, $pic, [$this->pdf('two.pdf')])->sole()->storage_path,
            $this->upload($requirementThree, $pic, [$this->pdf('three.pdf')])->sole()->storage_path,
            $this->upload($secondRequirement, $pic, [$this->pdf('four.pdf')])->sole()->storage_path,
        ];

        $this->assertNotSame($this->requirementDirectory($paths[0]), $this->requirementDirectory($paths[1]));
        $this->assertNotSame($this->requirementDirectory($paths[0]), $this->requirementDirectory($paths[2]));
        $this->assertNotSame($this->requirementDirectory($paths[0]), $this->requirementDirectory($paths[3]));
        $this->assertStringContainsString("bioteknologi--id-{$programOne->id}/projects/PROJECT-ONE", $paths[0]);
        $this->assertStringContainsString("bioteknologi--id-{$programOne->id}/projects/PROJECT-THREE", $paths[2]);
        $this->assertStringContainsString("R1--id-{$requirementOne->id}", $paths[0]);
        $this->assertStringContainsString("R2--id-{$secondRequirement->id}", $paths[3]);
    }

    public function test_multi_file_upload_shares_revision_directory_but_uses_unique_uuids(): void
    {
        [, , $criterion, , $requirement] = $this->structure(
            programName: 'Teknologi Pangan',
            projectCode: 'PROJECT-MULTI',
            criterionCode: 'D',
        );
        $pic = $this->picFor($criterion);

        $documents = $this->upload($requirement, $pic, [
            $this->pdf('same.pdf', $this->pdfContents('one')),
            $this->pdf('same.pdf', $this->pdfContents('two')),
        ]);

        $this->assertCount(2, $documents);
        $this->assertCount(1, $documents->map(fn (AccreditationDocument $document): string => dirname($document->storage_path))->unique());
        $this->assertCount(2, $documents->pluck('storage_uuid')->unique());
        $this->assertCount(2, $documents->pluck('storage_path')->unique());
        $this->assertSame([1], $documents->pluck('revision_number')->unique()->values()->all());
    }

    public function test_legacy_storage_path_remains_available_for_preview_and_download(): void
    {
        [, , $criterion, , $requirement] = $this->structure(
            programName: 'Legacy Program',
            projectCode: 'PROJECT-LEGACY',
            criterionCode: 'E',
        );
        $pic = $this->picFor($criterion);
        $legacyPath = 'accreditation/legacy-layout/'.$requirement->id.'/evidence.pdf';
        $contents = $this->pdfContents('legacy');
        Storage::disk('local')->put($legacyPath, $contents, ['visibility' => 'private']);
        $document = $this->document($requirement, $pic, $legacyPath, $contents);

        $this->actingAs($pic)
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertViewIs('documents.preview')
            ->assertSee(route('documents.preview.content', $document), false);
        $this->get(route('documents.preview.content', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->get(route('documents.download', $document))
            ->assertOk()
            ->assertDownload('legacy-evidence.pdf');
        Storage::disk('local')->assertExists($legacyPath);
    }

    public function test_failed_upload_removes_new_file_without_touching_legacy_file(): void
    {
        [, , $criterion, , $requirement] = $this->structure(
            programName: 'Rollback Program',
            projectCode: 'PROJECT-ROLLBACK',
            criterionCode: 'F',
        );
        $pic = $this->picFor($criterion);
        $legacyPath = 'accreditation/'.$requirement->id.'/legacy.pdf';
        $legacyContents = $this->pdfContents('legacy-preserved');
        Storage::disk('local')->put($legacyPath, $legacyContents, ['visibility' => 'private']);
        $legacyDocument = $this->document($requirement, $pic, $legacyPath, $legacyContents);
        $this->actingAs($pic);
        Schema::drop('accreditation_activity_logs');

        try {
            app(AccreditationDocumentUploadService::class)->uploadBatch(
                $requirement,
                $pic,
                [$this->pdf('new-file.pdf')],
            );
            $this->fail('Upload seharusnya gagal saat audit log tidak dapat disimpan.');
        } catch (QueryException) {
            $this->assertSame([$legacyPath], Storage::disk('local')->allFiles('accreditation'));
            Storage::disk('local')->assertExists($legacyPath);
            $this->assertSame($legacyDocument->id, AccreditationDocument::sole()->id);
            $this->assertSame(hash('sha256', $legacyContents), hash('sha256', Storage::disk('local')->get($legacyPath)));
        }
    }

    public function test_unsafe_database_identifiers_are_normalized_and_path_components_are_validated(): void
    {
        [, , , , $requirement] = $this->structure(
            programName: '../../ Program Studi',
            projectCode: '../PROJECT\\UNSAFE',
            criterionCode: '../../C\\unsafe',
        );
        $paths = app(AccreditationDocumentPathService::class);
        $uuid = (string) Str::uuid();
        $path = $paths->build($requirement, 1, $uuid, 'PDF');

        $this->assertStringStartsWith('accreditation/study-programs/program-studi--id-', $path);
        $this->assertStringContainsString('/projects/PROJECT-UNSAFE--id-', $path);
        $this->assertStringContainsString('/criteria/C-unsafe/items/C-unsafe.2--id-', $path);
        $this->assertStringEndsWith("/revisions/v1/{$uuid}.pdf", $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('\\', $path);

        foreach ([['not-a-uuid', 'pdf'], [$uuid, '../pdf'], [$uuid, 'php']] as [$unsafeUuid, $unsafeExtension]) {
            try {
                $paths->build($requirement, 1, $unsafeUuid, $unsafeExtension);
                $this->fail('Komponen storage path yang tidak aman seharusnya ditolak.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('UUID atau ekstensi storage tidak aman.', $exception->getMessage());
            }
        }
    }

    private function structure(
        string $programName,
        string $projectCode,
        string $criterionCode,
        int $itemNumber = 2,
        int $requirementNumber = 1,
        string $workflowStatus = 'not_started',
        int $currentRevision = 0,
        ?StudyProgram $studyProgram = null,
    ): array {
        $suffix = strtoupper(Str::random(8));
        $studyProgram ??= StudyProgram::create([
            'code' => 'PROGRAM-'.$suffix,
            'name' => $programName,
            'is_active' => true,
        ]);
        $project = AccreditationProject::create([
            'code' => $projectCode,
            'name' => 'Akreditasi '.$suffix,
            'study_program_id' => $studyProgram->id,
            'final_deadline' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $criterion = AccreditationCriterion::create([
            'project_id' => $project->id,
            'code' => $criterionCode,
            'name' => 'Kriteria '.$suffix,
            'color_header' => '#173E70',
            'color_body' => '#E7EEF8',
            'position' => 1,
        ]);
        $item = AccreditationItem::create([
            'criterion_id' => $criterion->id,
            'number' => $itemNumber,
            'position' => $itemNumber,
            'title' => 'Butir '.$suffix,
        ]);
        $requirement = AccreditationDocumentRequirement::create([
            'item_id' => $item->id,
            'number' => $requirementNumber,
            'title' => 'Rincian '.$suffix,
            'is_required' => true,
            'workflow_status' => $workflowStatus,
            'current_review_revision' => $currentRevision,
        ]);

        return [$studyProgram, $project, $criterion, $item, $requirement];
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role.' '.Str::random(5),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make(Str::random(20)),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function picFor(AccreditationCriterion $criterion): User
    {
        $pic = $this->userWithRole('PIC Kriteria');
        $this->assignment($criterion, $pic);

        return $pic;
    }

    private function assignment(AccreditationCriterion $criterion, User $user): void
    {
        AccreditationCriterionAssignment::create([
            'criterion_id' => $criterion->id,
            'user_id' => $user->id,
            'assignment_role' => 'pic',
            'is_active' => true,
        ]);
    }

    private function upload(AccreditationDocumentRequirement $requirement, User $user, array $files)
    {
        $this->actingAs($user);

        return app(AccreditationDocumentUploadService::class)->uploadBatch($requirement, $user, $files);
    }

    private function requirementDirectory(string $path): string
    {
        return Str::before($path, '/revisions/');
    }

    private function pdf(string $name, ?string $contents = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents ?? $this->pdfContents('valid-document'));
    }

    private function pdfContents(string $marker): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Marker ({$marker}) >>\nendobj\n"
            ."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            ."trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n64\n%%EOF\n";
    }

    private function document(
        AccreditationDocumentRequirement $requirement,
        User $uploader,
        string $path,
        string $contents,
    ): AccreditationDocument {
        return AccreditationDocument::create([
            'requirement_id' => $requirement->id,
            'uploaded_by' => $uploader->id,
            'original_name' => 'legacy-evidence.pdf',
            'disk' => 'local',
            'storage_path' => $path,
            'storage_uuid' => (string) Str::uuid(),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'revision_number' => 1,
            'is_active' => true,
            'uploaded_at' => now(),
        ]);
    }
}
