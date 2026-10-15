<?php

use App\Http\Controllers\AccreditationDocumentAccessController;
use App\Http\Controllers\AccreditationOfficePreviewController;
use App\Http\Controllers\AccreditationReviewAttachmentAccessController;
use App\Http\Controllers\Auth\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Documents\Index as DocumentIndex;
use App\Livewire\Documents\Upload as DocumentUpload;
use App\Livewire\Documents\VerificationIndex;
use App\Livewire\Documents\VerificationShow;
use App\Livewire\FollowUpLed\Index as FollowUpLedIndex;
use App\Livewire\Repository\Index as RepositoryIndex;
use App\Livewire\TeamActivity\Index as TeamActivityIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/follow-up-led', FollowUpLedIndex::class)->name('follow-up-led.index');
    Route::get('/input-dokumen', DocumentIndex::class)->name('documents.index');
    Route::get('/repository-bukti', RepositoryIndex::class)->name('repository.index');
    Route::get('/aktivitas-tim', TeamActivityIndex::class)->name('team-activity.index');
    Route::get('/document-requirements/{requirement}/upload', DocumentUpload::class)->name('documents.upload');
    Route::get('/document-verifications', VerificationIndex::class)->name('verifications.index');
    Route::get('/document-verifications/{submission}', VerificationShow::class)->name('verifications.show');
    Route::get('/document-verifications/{submission}/documents/{document}/open', [AccreditationDocumentAccessController::class, 'openForSubmission'])->name('verifications.documents.open');
    Route::get('/document-verifications/{submission}/documents/{document}/download', [AccreditationDocumentAccessController::class, 'downloadForSubmission'])->name('verifications.documents.download');
    Route::get('/document-verifications/{submission}/correction-attachments/{attachment}/open', [AccreditationReviewAttachmentAccessController::class, 'open'])->name('verifications.attachments.open');
    Route::get('/document-verifications/{submission}/correction-attachments/{attachment}/download', [AccreditationReviewAttachmentAccessController::class, 'download'])->name('verifications.attachments.download');
    Route::post('/document-verifications/{submission}/documents/{document}/office-preview', [AccreditationOfficePreviewController::class, 'requestPreview'])->middleware('throttle:10,1')->name('verifications.documents.office-preview.request');
    Route::get('/document-verifications/{submission}/documents/{document}/office-preview/status', [AccreditationOfficePreviewController::class, 'status'])->middleware('throttle:60,1')->name('verifications.documents.office-preview.status');
    Route::get('/document-verifications/{submission}/documents/{document}/office-preview/content', [AccreditationOfficePreviewController::class, 'content'])->middleware('throttle:60,1')->name('verifications.documents.office-preview.content');
    Route::get('/documents/{document}/preview', [AccreditationDocumentAccessController::class, 'preview'])->name('documents.preview');
    Route::get('/documents/{document}/preview/content', [AccreditationDocumentAccessController::class, 'content'])->name('documents.preview.content');
    Route::get('/documents/{document}/download', [AccreditationDocumentAccessController::class, 'download'])->name('documents.download');
    Route::post('/logout', LogoutController::class)->name('logout');
});
