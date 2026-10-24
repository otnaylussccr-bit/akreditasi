<div class="led-page led-setup-page">
    <header class="led-page-head">
        <div>
            <span class="eyebrow dark">{{ __('led_setup.header.eyebrow') }}</span>
            <h1>{{ __('led_setup.header.title') }}</h1>
            <p>{{ __('led_setup.header.description') }}</p>
        </div>
        <div class="led-head-actions">
            <select wire:change="selectProject($event.target.value)" aria-label="{{ __('led_setup.header.select_project') }}">
                @foreach ($projects as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $project->id)>{{ $option->code }} · {{ $option->name }}</option>
                @endforeach
            </select>
            <a href="{{ route('led-assessment.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('led_setup.header.open_assessment') }}</a>
        </div>
    </header>

    @if ($successMessage)
        <div class="led-notice" role="status">{{ $successMessage }}</div>
    @endif
    @if ($errors->any() && ! $errors->hasAny(['bulk_assignment_reason', 'bulk_assignment_indicators', 'bulk_assignment_users', 'assignment', 'replacement', 'revocation', 'confirmationReason', 'bulk_mapping_role', 'bulk_mapping_indicators', 'bulk_mapping_items', 'mapping', 'mappingRevocation', 'assignmentUserId', 'assignmentRole', 'mappingItemId', 'mappingRole', 'mappingRevocationReason']))
        <div class="led-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="led-context-strip">
        <div><span>{{ __('led_setup.context.project') }}</span><strong>{{ $project->name }}</strong><small>{{ \App\Support\LocalizedLabel::status($project->status) }}</small></div>
        <div><span>{{ __('led_setup.context.study_program') }}</span><strong>{{ $project->studyProgram->name }}</strong><small>{{ $project->studyProgram->code }}</small></div>
        <div><span>{{ __('led_setup.context.instrument') }}</span><strong>{{ $project->instrument->name }}</strong><small>{{ $project->instrument->code }}</small></div>
        <div><span>{{ __('led_setup.context.readiness') }}</span><strong>{{ $cycle ? __('led_setup.context.configured_indicators', ['configured' => $readiness['configured'], 'total' => $readiness['total']]) : __('led_setup.context.no_cycle') }}</strong><small>{{ $cycle && $readiness['ready'] ? __('led_setup.context.ready_to_activate') : __('led_setup.context.incomplete') }}</small></div>
    </section>

    <section class="setup-card">
        <header class="setup-card-head">
            <span>01</span>
            <div><h2>{{ __('led_setup.versions.title') }}</h2><p>{{ __('led_setup.versions.description') }}</p></div>
            <strong class="led-status {{ $publishedVersions->isNotEmpty() ? 'status-active' : 'status-revision_requested' }}">{{ __('led_setup.versions.published_count', ['count' => $publishedVersions->count()]) }}</strong>
        </header>

        @if ($publishedVersions->isEmpty())
            <div class="setup-readiness-warning">
                <strong>{{ __('led_setup.versions.none_published') }}</strong>
                <p>{{ __('led_setup.versions.none_published_help') }}</p>
            </div>
        @endif

        <div class="setup-version-grid">
            <div class="setup-version-list">
                @forelse ($versions as $version)
                    <article wire:key="instrument-version-{{ $version->id }}">
                        <div>
                            <span class="led-status status-{{ $version->status }}">{{ \App\Support\LocalizedLabel::status($version->status) }}</span>
                            <h3>{{ $version->version_code }}</h3>
                            <p>{{ $version->source_title }} · {{ $version->source_reference }}</p>
                            <small>{{ trans_choice('led_setup.versions.summary', $version->indicators_count, ['count' => $version->indicators_count, 'pdf_hash' => str($version->source_pdf_sha256)->limit(14), 'manifest_hash' => str($version->rubric_manifest_sha256)->limit(14)]) }}</small>
                        </div>
                        @if ($version->status === 'draft' && auth()->user()->hasRole('Super Admin'))
                            <button class="primary-button" type="button" wire:click="publishVersion({{ $version->id }})" wire:confirm="{{ __('led_setup.confirm.publish_version') }}" wire:loading.attr="disabled">{{ __('led_setup.actions.publish') }}</button>
                        @endif
                    </article>
                @empty
                    <div class="led-empty"><strong>{{ __('led_setup.versions.empty') }}</strong><p>{{ __('led_setup.versions.empty_help') }}</p></div>
                @endforelse
            </div>

            @if (auth()->user()->hasRole('Super Admin'))
                <form class="setup-import-panel" wire:submit="previewImport">
                    <div class="setup-panel-heading"><strong>{{ __('led_setup.import.title') }}</strong><small>{{ __('led_setup.import.description') }}</small></div>
                    <label>{{ __('led_setup.import.source_pdf') }}
                        <input type="file" wire:model="sourcePdf" accept="application/pdf,.pdf">
                    </label>
                    @error('sourcePdf') <p class="field-error">{{ $message }}</p> @enderror
                    <label>{{ __('led_setup.import.manifest') }}
                        <input type="file" wire:model="manifestFile" accept="application/json,.json">
                    </label>
                    @error('manifestFile') <p class="field-error">{{ $message }}</p> @enderror
                    <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="sourcePdf,manifestFile,previewImport">{{ __('led_setup.actions.validate_preview') }}</button>

                    @if ($importPreview)
                        <div class="setup-import-preview">
                            <header><strong>{{ $importPreview['metadata']['version_code'] }}</strong><span>{{ __('led_setup.import.unsaved_draft') }}</span></header>
                            <dl>
                                <div><dt>{{ __('led_setup.import.pathway') }}</dt><dd>{{ $importPreview['metadata']['assessment_pathway'] }}</dd></div>
                                <div><dt>{{ __('led_setup.import.education_level') }}</dt><dd>{{ $importPreview['metadata']['education_level'] }}</dd></div>
                                <div><dt>{{ __('led_setup.labels.indicators') }}</dt><dd>{{ __('led_setup.import.indicator_counts', ['count' => $importPreview['indicator_count'], 'assessable' => $importPreview['assessable_count']]) }}</dd></div>
                                <div><dt>{{ __('led_setup.labels.rubrics') }}</dt><dd>{{ $importPreview['rubric_count'] }}</dd></div>
                            </dl>
                            <p><strong>{{ __('led_setup.labels.pdf_sha256') }}</strong><code>{{ $importPreview['source_pdf_sha256'] }}</code></p>
                            <p><strong>{{ __('led_setup.labels.manifest_sha256') }}</strong><code>{{ $importPreview['rubric_manifest_sha256'] }}</code></p>
                            <div class="setup-preview-indicators">
                                @foreach ($importPreview['indicators'] as $indicator)
                                    <div><b>{{ $indicator['indicator_number'] }}</b><span>{{ $indicator['title'] }}</span><small>{{ trans_choice('led_setup.import.rubric_count', count($indicator['rubrics']), ['count' => count($indicator['rubrics'])]) }}</small></div>
                                @endforeach
                            </div>
                            <button class="primary-button" type="button" wire:click="saveImport" wire:confirm="{{ __('led_setup.confirm.save_draft') }}" wire:loading.attr="disabled">{{ __('led_setup.actions.save_as_draft') }}</button>
                        </div>
                    @endif
                </form>
            @else
                <div class="workflow-lock-notice"><strong>{{ __('led_setup.import.super_admin_only') }}</strong><span>{{ __('led_setup.import.manager_help') }}</span></div>
            @endif
        </div>
    </section>

    <section class="setup-card">
        <header class="setup-card-head">
            <span>02</span>
            <div><h2>{{ __('led_setup.cycles.title') }}</h2><p>{{ __('led_setup.cycles.description') }}</p></div>
            @if ($cycle)<strong class="led-status status-{{ $cycle->status }}">{{ \App\Support\LocalizedLabel::status($cycle->status) }}</strong>@endif
        </header>

        <div class="setup-cycle-grid">
            <form class="setup-inline-form" wire:submit="createCycle">
                <label>{{ __('led_setup.cycles.published_version') }}
                    <select wire:model="versionId" @disabled($publishedVersions->isEmpty())>
                        <option value="">{{ __('led_setup.cycles.select_version') }}</option>
                        @foreach ($publishedVersions as $version)<option value="{{ $version->id }}">{{ $version->version_code }} · {{ $version->source_title }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('led_setup.cycles.name') }}
                    <input type="text" wire:model="cycleName" maxlength="255" placeholder="{{ __('led_setup.cycles.name_placeholder') }}" @disabled($publishedVersions->isEmpty())>
                </label>
                <button class="primary-button" type="submit" @disabled($publishedVersions->isEmpty()) wire:loading.attr="disabled">{{ __('led_setup.actions.create_draft_cycle') }}</button>
            </form>
            <div class="setup-cycle-list">
                @forelse ($cycles as $option)
                    <button class="{{ $cycle?->id === $option->id ? 'active' : '' }}" type="button" wire:click="selectCycle({{ $option->id }})" wire:key="cycle-{{ $option->id }}">
                        <span><strong>#{{ $option->cycle_number }} {{ $option->cycle_name }}</strong><small>{{ $option->instrumentVersion->version_code }}</small></span>
                        <em class="led-status status-{{ $option->status }}">{{ \App\Support\LocalizedLabel::status($option->status) }}</em>
                    </button>
                @empty
                    <div class="led-empty"><strong>{{ __('led_setup.cycles.empty') }}</strong><p>{{ __('led_setup.cycles.empty_help') }}</p></div>
                @endforelse
            </div>
        </div>
    </section>

    @if ($cycle)
        <section class="setup-card">
            <header class="setup-card-head">
                <span>03</span>
                <div><h2>{{ __('led_setup.operations.title') }}</h2><p>{{ __('led_setup.operations.description') }}</p></div>
                <strong>{{ $cycle->instrumentVersion->version_code }}</strong>
            </header>

            @if (in_array($cycle->status, ['closed', 'finalized'], true))
                <div class="workflow-lock-notice"><strong>{{ __('led_setup.operations.cycle_status', ['status' => \App\Support\LocalizedLabel::status($cycle->status)]) }}</strong><span>{{ __('led_setup.operations.locked') }}</span></div>
            @elseif ($cycle->status === 'active')
                <div class="led-notice">{{ __('led_setup.operations.active_notice') }}</div>
            @endif

            @if (in_array($cycle->status, ['draft', 'active'], true))
                <div class="setup-bulk-grid">
                    <section class="setup-bulk-panel">
                        <header><div><strong>{{ __('led_setup.bulk_assignment.title') }}</strong><small>{{ __('led_setup.bulk_assignment.description') }}</small></div></header>
                        <div class="setup-bulk-body">
                            @if ($bulkAssignmentMessage)
                                <div class="setup-action-feedback led-notice" role="status" data-feedback-scope="bulk-assignment">{{ $bulkAssignmentMessage }}</div>
                            @endif
                            @if ($operationalFeedbackContext === 'bulk_assignment')
                                @error('assignment') <div class="setup-action-feedback led-error" role="alert">{{ $message }}</div> @enderror
                            @endif
                            <div class="setup-bulk-selection @error('bulk_assignment_indicators') setup-selection-invalid @enderror" role="group" aria-label="{{ __('led_setup.bulk_assignment.indicator_group_label') }}" @error('bulk_assignment_indicators') aria-describedby="bulk-assignment-indicators-error" @enderror>
                                @foreach ($indicatorGroups as $group)
                                    <fieldset>
                                        <legend>{{ $group['code'] }} · {{ $group['name'] }}</legend>
                                        @foreach ($group['assessments'] as $assessment)
                                            <label><input type="checkbox" wire:model.live="bulkAssignmentIndicatorIds" value="{{ $assessment->indicator_id }}"><span><b>{{ $assessment->indicator->indicator_number }}</b>{{ $assessment->indicator->title }}</span></label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>
                            @error('bulk_assignment_indicators') <p class="setup-field-error" id="bulk-assignment-indicators-error" role="alert">{{ $message }}</p> @enderror
                            <div class="setup-bulk-fields @error('bulk_assignment_users') setup-selection-invalid @enderror">
                                <label>{{ __('led_setup.assignment_roles.primary') }}
                                    <select wire:model.live="bulkPrimaryUserId" @error('bulk_assignment_users') aria-invalid="true" aria-describedby="bulk-assignment-users-error" @enderror><option value="">{{ __('led_setup.labels.unchanged') }}</option>@foreach ($candidateUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>@endforeach</select>
                                </label>
                                <label>{{ __('led_setup.assignment_roles.support') }}
                                    <select wire:model.live="bulkSupportUserId" @error('bulk_assignment_users') aria-invalid="true" aria-describedby="bulk-assignment-users-error" @enderror><option value="">{{ __('led_setup.labels.unchanged') }}</option>@foreach ($candidateUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>@endforeach</select>
                                </label>
                                <label>{{ __('led_setup.assignment_roles.reviewer') }}
                                    <select wire:model.live="bulkReviewerUserId" @error('bulk_assignment_users') aria-invalid="true" aria-describedby="bulk-assignment-users-error" @enderror><option value="">{{ __('led_setup.labels.unchanged') }}</option>@foreach ($candidateUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>@endforeach</select>
                                </label>
                                @error('bulk_assignment_users') <p class="setup-field-error" id="bulk-assignment-users-error" role="alert">{{ $message }}</p> @enderror
                                <label class="setup-bulk-reason"><span class="setup-field-label">{{ __('led_setup.bulk_assignment.reason') }}<span class="setup-required" aria-hidden="true">&nbsp;*</span></span>
                                    <input id="bulk-assignment-reason" class="@error('bulk_assignment_reason') setup-input-invalid @enderror" type="text" wire:model="bulkAssignmentReason" maxlength="1000" placeholder="{{ __('led_setup.bulk_assignment.reason_placeholder') }}" aria-required="true" @error('bulk_assignment_reason') aria-invalid="true" aria-describedby="bulk-assignment-reason-error" @enderror>
                                </label>
                                @error('bulk_assignment_reason') <p class="setup-field-error" id="bulk-assignment-reason-error" role="alert" x-data x-init="$nextTick(() => document.getElementById('bulk-assignment-reason')?.focus())">{{ $message }}</p> @enderror
                                <button class="primary-button" type="button" wire:click="previewBulkAssignments" wire:loading.attr="disabled">{{ __('led_setup.actions.preview_assignments') }}</button>
                            </div>
                            @if ($bulkAssignmentPreview)
                                <div class="setup-bulk-preview">
                                    <div class="setup-bulk-preview-summary"><strong>{{ trans_choice('led_setup.preview.created', $bulkAssignmentPreview['create_count'], ['count' => $bulkAssignmentPreview['create_count']]) }}</strong><span>{{ trans_choice('led_setup.preview.skipped', $bulkAssignmentPreview['skipped_count'], ['count' => $bulkAssignmentPreview['skipped_count']]) }} · {{ trans_choice('led_setup.preview.conflicts', $bulkAssignmentPreview['conflict_count'], ['count' => $bulkAssignmentPreview['conflict_count']]) }}</span></div>
                                    <div class="setup-bulk-preview-list">
                                        @foreach ($bulkAssignmentPreview['rows'] as $row)
                                            <div class="status-{{ $row['action'] }}"><b>{{ $row['indicator_number'] }}</b><span>{{ $row['role_label'] }} · {{ $row['user_name'] }}</span><small>{{ $row['message'] ?: __('led_setup.preview.new_assignment') }}</small></div>
                                        @endforeach
                                    </div>
                                    <button class="primary-button" type="button" wire:click="openConfirmation('bulk_assignment')" @disabled(! $bulkAssignmentPreview['can_apply']) wire:loading.attr="disabled">{{ __('led_setup.actions.apply_bulk_assignments') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="setup-bulk-panel">
                        <header><div><strong>{{ __('led_setup.bulk_mapping.title') }}</strong><small>{{ __('led_setup.bulk_mapping.description') }}</small></div></header>
                        <div class="setup-bulk-body">
                            @if ($bulkMappingMessage)
                                <div class="setup-action-feedback led-notice" role="status" data-feedback-scope="bulk-mapping">{{ $bulkMappingMessage }}</div>
                            @endif
                            @if ($operationalFeedbackContext === 'bulk_mapping')
                                @error('mapping')
                                    <div class="setup-action-feedback led-error" role="alert">{{ $message }}</div>
                                @enderror
                            @endif
                            <div class="setup-bulk-selection @error('bulk_mapping_indicators') setup-selection-invalid @enderror" role="group" aria-label="{{ __('led_setup.bulk_mapping.indicator_group_label') }}" @error('bulk_mapping_indicators') aria-describedby="bulk-mapping-indicators-error" @enderror>
                                @foreach ($indicatorGroups as $group)
                                    <fieldset>
                                        <legend>{{ $group['code'] }} · {{ $group['name'] }}</legend>
                                        @foreach ($group['assessments'] as $assessment)
                                            <label><input type="checkbox" wire:model.live="bulkMappingIndicatorIds" value="{{ $assessment->indicator_id }}"><span><b>{{ $assessment->indicator->indicator_number }}</b>{{ $assessment->indicator->title }}</span></label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>
                            @error('bulk_mapping_indicators') <p class="setup-field-error" id="bulk-mapping-indicators-error" role="alert">{{ $message }}</p> @enderror
                            <div class="setup-bulk-items @error('bulk_mapping_items') setup-selection-invalid @enderror" role="group" aria-label="{{ __('led_setup.bulk_mapping.item_group_label') }}" @error('bulk_mapping_items') aria-describedby="bulk-mapping-items-error" @enderror>
                                @foreach ($projectItems->groupBy(fn ($item) => $item->criterion->code) as $criterionCode => $items)
                                    <fieldset>
                                        <legend>{{ __('led_setup.labels.criterion_with_code', ['code' => $criterionCode]) }}</legend>
                                        @foreach ($items as $item)
                                            <label><input type="checkbox" wire:model.live="bulkMappingItemIds" value="{{ $item->id }}"><span><b>{{ $item->criterion->code }}.{{ $item->number }}</b>{{ $item->title }}</span></label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>
                            @error('bulk_mapping_items') <p class="setup-field-error" id="bulk-mapping-items-error" role="alert">{{ $message }}</p> @enderror
                            <div class="setup-bulk-fields mapping @error('bulk_mapping_role') setup-selection-invalid @enderror">
                                <label>{{ __('led_setup.bulk_mapping.role') }}<select class="@error('bulk_mapping_role') setup-input-invalid @enderror" wire:model.live="bulkMappingRole" @error('bulk_mapping_role') aria-invalid="true" aria-describedby="bulk-mapping-role-error" @enderror><option value="primary">{{ \App\Support\LocalizedLabel::mappingRole('primary') }}</option><option value="supporting">{{ \App\Support\LocalizedLabel::mappingRole('supporting') }}</option></select></label>
                                @error('bulk_mapping_role') <p class="setup-field-error" id="bulk-mapping-role-error" role="alert">{{ $message }}</p> @enderror
                                <button class="primary-button" type="button" wire:click="previewBulkMappings" wire:loading.attr="disabled">{{ __('led_setup.actions.preview_mappings') }}</button>
                            </div>
                            @if ($bulkMappingPreview)
                                <div class="setup-bulk-preview">
                                    <div class="setup-bulk-preview-summary"><strong>{{ trans_choice('led_setup.preview.created', $bulkMappingPreview['create_count'], ['count' => $bulkMappingPreview['create_count']]) }}</strong><span>{{ trans_choice('led_setup.preview.skipped', $bulkMappingPreview['skipped_count'], ['count' => $bulkMappingPreview['skipped_count']]) }} · {{ trans_choice('led_setup.preview.conflicts', $bulkMappingPreview['conflict_count'], ['count' => $bulkMappingPreview['conflict_count']]) }}</span></div>
                                    <div class="setup-bulk-preview-list">
                                        @foreach ($bulkMappingPreview['rows'] as $row)
                                            <div class="status-{{ $row['action'] }}"><b>{{ $row['indicator_number'] }}</b><span>{{ $row['role_label'] }} · {{ $row['item_reference'] }} {{ $row['item_title'] }}</span><small>{{ $row['message'] ?: __('led_setup.preview.new_mapping') }}</small></div>
                                        @endforeach
                                    </div>
                                    <button class="primary-button" type="button" wire:click="openConfirmation('bulk_mapping')" @disabled(! $bulkMappingPreview['can_apply']) wire:loading.attr="disabled">{{ __('led_setup.actions.apply_bulk_mappings') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            @endif

            <div class="setup-indicator-tabs" role="tablist" aria-label="{{ __('led_setup.indicators.tablist_label') }}">
                @foreach ($indicatorGroups as $group)
                    <div class="setup-indicator-group">
                        <strong>{{ $group['code'] }}</strong>
                        @foreach ($group['assessments'] as $assessment)
                            @php $indicatorReadiness = collect($readiness['indicators'])->firstWhere('indicator_id', $assessment->indicator_id); @endphp
                            <button class="{{ $indicatorId === $assessment->indicator_id ? 'active' : '' }} {{ $indicatorReadiness['ready'] ? 'readiness-complete' : 'readiness-incomplete' }}" type="button" role="tab" aria-selected="{{ $indicatorId === $assessment->indicator_id ? 'true' : 'false' }}" wire:click="selectIndicator({{ $assessment->indicator_id }})" aria-label="{{ __('led_setup.indicators.tab_label', ['number' => $assessment->indicator->indicator_number, 'state' => $indicatorReadiness['ready'] ? __('led_setup.labels.complete') : __('led_setup.labels.incomplete')]) }}">
                                <b>{{ $assessment->indicator->indicator_number }}</b>
                                <span>{{ $assessment->indicator->title }}</span>
                                <small><i aria-hidden="true">{{ $indicatorReadiness['ready'] ? '✓' : '!' }}</i>{{ $indicatorReadiness['ready'] ? __('led_setup.labels.complete') : __('led_setup.labels.incomplete') }}</small>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>

            @if ($selectedIndicator)
                <div class="setup-operations-grid">
                    <section class="setup-operation-panel">
                        <header><div><strong>{{ __('led_setup.assignments.title') }}</strong><small>{{ __('led_setup.assignments.description') }}</small></div></header>
                        @if ($assignmentMessage)
                            <div class="setup-action-feedback led-notice" role="status" data-feedback-scope="assignment">{{ $assignmentMessage }}</div>
                        @endif
                        @if ($operationalFeedbackContext === 'assignment')
                            @error('assignment') <div class="setup-action-feedback led-error" role="alert">{{ $message }}</div> @enderror
                            @error('replacement') <div class="setup-action-feedback led-error" role="alert">{{ $message }}</div> @enderror
                        @endif
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <form class="setup-inline-form compact" wire:submit="openAssignmentConfirmation">
                                <select class="@error('assignmentRole') setup-input-invalid @enderror" wire:model="assignmentRole" aria-label="{{ __('led_setup.assignments.role_label') }}" @error('assignmentRole') aria-invalid="true" aria-describedby="assignment-role-error" @enderror>
                                    <option value="assessor_primary">{{ \App\Support\LocalizedLabel::assignmentRole('assessor_primary') }}</option>
                                    <option value="assessor_support">{{ \App\Support\LocalizedLabel::assignmentRole('assessor_support') }}</option>
                                    <option value="reviewer">{{ \App\Support\LocalizedLabel::assignmentRole('reviewer') }}</option>
                                </select>
                                <select class="@error('assignmentUserId') setup-input-invalid @enderror" wire:model="assignmentUserId" aria-label="{{ __('led_setup.assignments.select_user_label') }}" @error('assignmentUserId') aria-invalid="true" aria-describedby="assignment-user-error" @enderror>
                                    <option value="">{{ __('led_setup.assignments.select_user') }}</option>
                                    @foreach ($candidateUsers as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>
                                    @endforeach
                                </select>
                                <button class="primary-button" id="assignment-add" type="submit">{{ __('led_setup.actions.add') }}</button>
                            </form>
                            @error('assignmentRole') <p class="setup-field-error" id="assignment-role-error" role="alert">{{ $message }}</p> @enderror
                            @error('assignmentUserId') <p class="setup-field-error" id="assignment-user-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                        <div class="setup-history-list">
                            @forelse ($cycle->assignments->where('indicator_id', $selectedIndicator->id)->sortByDesc('assigned_at') as $assignment)
                                <article class="{{ $assignment->revoked_at ? 'revoked' : '' }}" wire:key="assignment-{{ $assignment->id }}">
                                    <div><strong>{{ $assignment->user->name }}</strong><small>{{ \App\Support\LocalizedLabel::assignmentRole($assignment->assignment_role) }} · {{ \App\Support\BusinessDateTime::label($assignment->assigned_at, 'd M Y H:i') }}</small></div>
                                    @if ($assignment->revoked_at)
                                        <span>{{ __('led_setup.labels.revoked') }}: {{ $assignment->revocation_reason }}</span>
                                    @elseif (in_array($cycle->status, ['draft', 'active'], true))
                                        <button id="assignment-revoke-{{ $assignment->id }}" type="button" wire:click="openConfirmation('revoke_assignment', {{ $assignment->id }})">{{ __('led_setup.actions.revoke') }}</button>
                                    @endif
                                </article>
                            @empty
                                <p class="setup-empty-line">{{ __('led_setup.assignments.empty') }}</p>
                            @endforelse
                        </div>
                    </section>

                    <section class="setup-operation-panel">
                        <header><div><strong>{{ __('led_setup.mappings.title') }}</strong><small>{{ __('led_setup.mappings.description') }}</small></div></header>
                        @if ($mappingMessage)
                            <div class="setup-action-feedback led-notice" role="status" data-feedback-scope="mapping">{{ $mappingMessage }}</div>
                        @endif
                        @if ($operationalFeedbackContext === 'mapping')
                            @error('mapping') <div class="setup-action-feedback led-error" role="alert">{{ $message }}</div> @enderror
                        @endif
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <form class="setup-inline-form compact" wire:submit="createMapping">
                                <select class="@error('mappingRole') setup-input-invalid @enderror" wire:model="mappingRole" aria-label="{{ __('led_setup.mappings.role_label') }}" @error('mappingRole') aria-invalid="true" aria-describedby="mapping-role-error" @enderror><option value="primary">{{ \App\Support\LocalizedLabel::mappingRole('primary') }}</option><option value="supporting">{{ \App\Support\LocalizedLabel::mappingRole('supporting') }}</option></select>
                                <select class="@error('mappingItemId') setup-input-invalid @enderror" wire:model="mappingItemId" aria-label="{{ __('led_setup.mappings.select_item_label') }}" @error('mappingItemId') aria-invalid="true" aria-describedby="mapping-item-error" @enderror>
                                    <option value="">{{ __('led_setup.mappings.select_item') }}</option>
                                    @foreach ($projectItems as $item)<option value="{{ $item->id }}">{{ $item->criterion->code }} · {{ $item->number }} · {{ str($item->title)->limit(70) }}</option>@endforeach
                                </select>
                                <button class="primary-button" type="submit">{{ __('led_setup.actions.link') }}</button>
                            </form>
                            @error('mappingRole') <p class="setup-field-error" id="mapping-role-error" role="alert">{{ $message }}</p> @enderror
                            @error('mappingItemId') <p class="setup-field-error" id="mapping-item-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                        <div class="setup-history-list">
                            @forelse ($cycle->itemMappings->where('indicator_id', $selectedIndicator->id)->sortByDesc('created_at') as $mapping)
                                <article class="{{ $mapping->revoked_at ? 'revoked' : '' }}" wire:key="mapping-{{ $mapping->id }}">
                                    <div><strong>{{ $mapping->item->criterion->code }} · {{ __('led_setup.labels.item_number', ['number' => $mapping->item->number]) }}</strong><small>{{ \App\Support\LocalizedLabel::mappingRole($mapping->mapping_role) }} · {{ str($mapping->item->title)->limit(75) }}</small></div>
                                    @if ($mapping->revoked_at)
                                        <span>{{ __('led_setup.labels.revoked') }}: {{ $mapping->revoke_reason }}</span>
                                    @elseif (in_array($cycle->status, ['draft', 'active'], true))
                                        <button type="button" wire:click="openConfirmation('revoke_mapping', {{ $mapping->id }})">{{ __('led_setup.actions.revoke') }}</button>
                                    @endif
                                </article>
                            @empty
                                <p class="setup-empty-line">{{ __('led_setup.mappings.empty') }}</p>
                            @endforelse
                        </div>
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <label class="setup-revoke-reason"><span class="setup-field-label">{{ __('led_setup.mappings.revocation_reason') }}<span class="setup-required" aria-hidden="true">&nbsp;*</span></span><input id="mapping-revocation-reason" class="{{ $errors->hasAny(['mappingRevocationReason', 'mappingRevocation']) ? 'setup-input-invalid' : '' }}" type="text" wire:model.live="mappingRevocationReason" maxlength="1000" placeholder="{{ __('led_setup.mappings.revocation_reason_placeholder') }}" aria-required="true" @if ($errors->hasAny(['mappingRevocationReason', 'mappingRevocation'])) aria-invalid="true" @endif @error('mappingRevocationReason') aria-describedby="mapping-revocation-reason-error" @enderror @error('mappingRevocation') aria-describedby="mapping-revocation-error" @enderror></label>
                            <p class="setup-field-help">{{ __('led_setup.mappings.revocation_reason_help') }}</p>
                            @error('mappingRevocationReason') <p class="setup-field-error" id="mapping-revocation-reason-error" role="alert">{{ $message }}</p> @enderror
                            @error('mappingRevocation') <p class="setup-field-error" id="mapping-revocation-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                    </section>
                </div>
            @endif
        </section>

        <section class="setup-card">
            <header class="setup-card-head">
                <span>04</span>
                <div><h2>{{ __('led_setup.readiness.title') }}</h2><p>{{ __('led_setup.readiness.description') }}</p></div>
                <strong class="led-status {{ $readiness['ready'] ? 'status-active' : 'status-revision_requested' }}">{{ $readiness['ready'] ? __('led_setup.labels.ready') : __('led_setup.labels.not_ready') }}</strong>
            </header>
            <div class="setup-readiness-summary">
                <div><span>{{ __('led_setup.readiness.configured') }}</span><strong>{{ $readiness['configured'] }}/{{ $readiness['total'] }}</strong></div>
                <div><span>{{ __('led_setup.readiness.missing_primary') }}</span><strong>{{ $readiness['missing_primary'] }}</strong></div>
                <div><span>{{ __('led_setup.readiness.missing_reviewer') }}</span><strong>{{ $readiness['missing_reviewer'] }}</strong></div>
                <div><span>{{ __('led_setup.readiness.missing_mapping') }}</span><strong>{{ $readiness['missing_mapping'] }}</strong></div>
            </div>
            <div class="setup-readiness-criteria">
                @foreach ($readiness['criteria'] as $criterion)
                    @php $readinessTarget = collect($criterion['indicators'])->firstWhere('ready', false) ?? $criterion['indicators'][0]; @endphp
                    <button type="button" wire:click="selectIndicator({{ $readinessTarget['indicator_id'] }})">
                        <span><b>{{ $criterion['criterion_code'] }}</b>{{ $criterion['criterion_name'] }}</span>
                        <strong>{{ $criterion['configured'] }}/{{ $criterion['total'] }}</strong>
                        <small>{{ __('led_setup.readiness.criterion_missing', ['primary' => $criterion['missing_primary'], 'reviewer' => $criterion['missing_reviewer'], 'mapping' => $criterion['missing_mapping']]) }}</small>
                        <progress value="{{ $criterion['configured'] }}" max="{{ $criterion['total'] }}">{{ $criterion['configured'] }}/{{ $criterion['total'] }}</progress>
                    </button>
                @endforeach
            </div>
            <div class="admin-table-wrap setup-readiness-table">
                <table>
                    <thead><tr><th scope="col">{{ __('led_setup.labels.indicator') }}</th><th scope="col">{{ __('led_setup.assignment_roles.primary') }}</th><th scope="col">{{ __('led_setup.readiness.independent_reviewer') }}</th><th scope="col">{{ __('led_setup.labels.mapping') }}</th><th scope="col">{{ __('led_setup.labels.status') }}</th></tr></thead>
                    <tbody>
                        @foreach ($readiness['indicators'] as $row)
                            <tr class="{{ $row['ready'] ? 'readiness-complete' : 'readiness-incomplete' }}">
                                <td><strong>{{ $row['indicator_number'] }}</strong><span>{{ $row['title'] }}</span></td>
                                <td>@if ($row['primary_assignees'] !== [])<ul class="setup-assignee-list">@foreach ($row['primary_assignees'] as $name)<li>{{ $name }}</li>@endforeach</ul>@else<span class="setup-assignee-missing">{{ __('led_setup.labels.missing') }}</span>@endif</td>
                                <td>@if ($row['independent_reviewer_assignees'] !== [])<ul class="setup-assignee-list">@foreach ($row['independent_reviewer_assignees'] as $name)<li>{{ $name }}</li>@endforeach</ul>@else<span class="setup-assignee-missing">{{ __('led_setup.labels.missing') }}</span>@endif</td>
                                <td>{{ $row['mapping_count'] }}</td>
                                <td><button class="setup-readiness-link" type="button" wire:click="selectIndicator({{ $row['indicator_id'] }})"><span class="setup-readiness-state {{ $row['ready'] ? 'complete' : 'incomplete' }}"><i aria-hidden="true">{{ $row['ready'] ? '✓' : '!' }}</i>{{ $row['ready'] ? __('led_setup.labels.complete') : __('led_setup.labels.incomplete') }}</span></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($cycle->status === 'draft')
                <div class="setup-activation-action">
                    <p>{{ __('led_setup.readiness.activation_help') }}</p>
                    <button class="primary-button" type="button" wire:click="activateCycle" wire:confirm="{{ __('led_setup.confirm.activate_cycle') }}" @disabled(! $readiness['ready']) wire:loading.attr="disabled">{{ __('led_setup.actions.activate_cycle') }}</button>
                </div>
            @else
                <div class="led-notice">{{ __('led_setup.readiness.active_notice') }}</div>
            @endif
        </section>
    @endif

    @if ($confirmationAction)
        <div class="setup-confirmation-backdrop">
            <section class="setup-confirmation-modal" role="dialog" aria-modal="true" aria-labelledby="setup-confirmation-title" aria-describedby="setup-confirmation-description" tabindex="-1" x-data x-init="$nextTick(() => $el.querySelector('[data-confirm-reason], [data-confirm-cancel]')?.focus())" x-on:keydown.escape="$wire.cancelConfirmation().then(() => $nextTick(() => document.getElementById('{{ $confirmationFocusReturnId }}')?.focus()))">
                @php
                    $confirmationPreview = null;
                    $confirmationAssignment = $confirmationTargetId ? $cycle->assignments->firstWhere('id', $confirmationTargetId) : null;
                    $confirmationUser = $confirmationUserId ? $candidateUsers->firstWhere('id', $confirmationUserId) : null;
                    $confirmationCurrentLead = $confirmationPrimaryId ? $cycle->assignments->firstWhere('id', $confirmationPrimaryId) : null;
                @endphp
                @if ($confirmationAction === 'bulk_assignment')
                    <h2 id="setup-confirmation-title">{{ __('led_setup.confirmation_modal.bulk_assignment_title') }}</h2>
                    <p id="setup-confirmation-description">{{ __('led_setup.confirmation_modal.bulk_assignment_body') }}</p>
                    @php($confirmationPreview = $bulkAssignmentPreview)
                @elseif ($confirmationAction === 'bulk_mapping')
                    <h2 id="setup-confirmation-title">{{ __('led_setup.confirmation_modal.bulk_mapping_title') }}</h2>
                    <p id="setup-confirmation-description">{{ __('led_setup.confirmation_modal.bulk_mapping_body') }}</p>
                    @php($confirmationPreview = $bulkMappingPreview)
                @elseif ($confirmationAction === 'add_assignment')
                    <h2 id="setup-confirmation-title">{{ __('led_setup.confirmation_modal.add_assignment_title') }}</h2>
                    <p id="setup-confirmation-description">{{ __('led_setup.confirmation_modal.add_assignment_body') }}</p>
                @elseif ($confirmationAction === 'replace_assignment')
                    <h2 id="setup-confirmation-title">{{ __('led_setup.confirmation_modal.replace_assignment_title') }}</h2>
                    <p id="setup-confirmation-description">{{ __('led_setup.confirmation_modal.replace_assignment_body') }}</p>
                @elseif ($confirmationAction === 'revoke_assignment')
                    <h2 id="setup-confirmation-title">{{ __('led_setup.confirmation_modal.revoke_assignment_title') }}</h2>
                    <p id="setup-confirmation-description">{{ __('led_setup.confirmation_modal.revoke_assignment_body') }}</p>
                @else
                    <h2 id="setup-confirmation-title">{{ __('led_setup.confirmation_modal.revoke_mapping_title') }}</h2>
                    <p id="setup-confirmation-description">{{ __('led_setup.confirmation_modal.revoke_mapping_body') }}</p>
                @endif

                @if ($confirmationPreview)
                    <dl class="setup-confirmation-counts">
                        <div><dt>{{ trans_choice('led_setup.preview.created', $confirmationPreview['create_count'], ['count' => $confirmationPreview['create_count']]) }}</dt><dd>{{ $confirmationPreview['create_count'] }}</dd></div>
                        <div><dt>{{ trans_choice('led_setup.preview.skipped', $confirmationPreview['skipped_count'], ['count' => $confirmationPreview['skipped_count']]) }}</dt><dd>{{ $confirmationPreview['skipped_count'] }}</dd></div>
                        <div><dt>{{ trans_choice('led_setup.preview.conflicts', $confirmationPreview['conflict_count'], ['count' => $confirmationPreview['conflict_count']]) }}</dt><dd>{{ $confirmationPreview['conflict_count'] }}</dd></div>
                    </dl>
                @endif

                @if (in_array($confirmationAction, ['add_assignment', 'replace_assignment', 'revoke_assignment'], true))
                    <dl class="setup-confirmation-context">
                        <div><dt>{{ __('led_setup.confirmation_modal.context_project') }}</dt><dd>{{ $project->name }}</dd></div>
                        <div><dt>{{ __('led_setup.confirmation_modal.context_cycle') }}</dt><dd>{{ $cycle->cycle_name }}</dd></div>
                        <div class="setup-confirmation-indicator"><dt>{{ __('led_setup.confirmation_modal.context_indicator') }}</dt><dd><strong>{{ $selectedIndicator?->indicator_number }}</strong><span>{{ $selectedIndicator?->title }}</span><details><summary>{{ __('led_setup.confirmation_modal.context_full_indicator_title') }}</summary><p>{{ $selectedIndicator?->title }}</p></details></dd></div>
                        @if ($confirmationAction === 'replace_assignment')
                            <div><dt>{{ __('led_setup.confirmation_modal.context_current_lead') }}</dt><dd>{{ $confirmationCurrentLead?->user?->name }}</dd></div>
                            <div><dt>{{ __('led_setup.confirmation_modal.context_new_lead') }}</dt><dd>{{ $confirmationUser?->name }}</dd></div>
                        @else
                            <div><dt>{{ __('led_setup.confirmation_modal.context_user') }}</dt><dd>{{ $confirmationAssignment?->user?->name ?? $confirmationUser?->name }}</dd></div>
                            <div><dt>{{ __('led_setup.confirmation_modal.context_role') }}</dt><dd>{{ $confirmationAssignment ? \App\Support\LocalizedLabel::assignmentRole($confirmationAssignment->assignment_role) : \App\Support\LocalizedLabel::assignmentRole($confirmationRole) }}</dd></div>
                        @endif
                    </dl>
                    <label class="setup-confirmation-reason" for="confirmation-reason"><span class="setup-confirmation-reason-heading">{{ __('led_setup.confirmation_modal.reason_label') }}<span class="setup-required" aria-hidden="true">&nbsp;*</span></span>
                        <textarea id="confirmation-reason" wire:model.live="confirmationReason" maxlength="1000" rows="4" aria-required="true" data-confirm-reason @if ($errors->hasAny(['confirmationReason', 'assignment', 'replacement', 'revocation'])) aria-invalid="true" @endif @error('confirmationReason') aria-describedby="confirmation-reason-error" @enderror @error('assignment') aria-describedby="confirmation-assignment-error" @enderror @error('replacement') aria-describedby="confirmation-replacement-error" @enderror @error('revocation') aria-describedby="confirmation-revocation-error" @enderror></textarea>
                    </label>
                    @error('confirmationReason') <p class="setup-field-error" id="confirmation-reason-error" role="alert">{{ $message }}</p> @enderror
                    @error('assignment') <p class="setup-field-error" id="confirmation-assignment-error" role="alert">{{ $message }}</p> @enderror
                    @error('replacement') <p class="setup-field-error" id="confirmation-replacement-error" role="alert">{{ $message }}</p> @enderror
                    @error('revocation') <p class="setup-field-error" id="confirmation-revocation-error" role="alert">{{ $message }}</p> @enderror
                @endif

                <p class="setup-confirmation-audit">{{ __('led_setup.confirmation_modal.audit_notice') }}</p>
                <div class="setup-confirmation-actions">
                    <button class="secondary-button" type="button" wire:click="cancelConfirmation" x-on:click="$nextTick(() => document.getElementById('{{ $confirmationFocusReturnId }}')?.focus())" data-confirm-cancel>{{ __('led_setup.actions.cancel') }}</button>
                    <button class="primary-button" type="button" wire:click="confirmOperation" wire:loading.attr="disabled" wire:target="confirmOperation">{{ $confirmationAction === 'replace_assignment' ? __('led_setup.actions.replace') : __('led_setup.actions.confirm') }}</button>
                </div>
            </section>
        </div>
    @endif
</div>
