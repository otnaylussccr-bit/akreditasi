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
    @if ($errors->any())
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
                            <div class="setup-bulk-selection" role="group" aria-label="{{ __('led_setup.bulk_assignment.indicator_group_label') }}">
                                @foreach ($indicatorGroups as $group)
                                    <fieldset>
                                        <legend>{{ $group['code'] }} · {{ $group['name'] }}</legend>
                                        @foreach ($group['assessments'] as $assessment)
                                            <label><input type="checkbox" wire:model.live="bulkAssignmentIndicatorIds" value="{{ $assessment->indicator_id }}"><span><b>{{ $assessment->indicator->indicator_number }}</b>{{ $assessment->indicator->title }}</span></label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>
                            <div class="setup-bulk-fields">
                                <label>{{ __('led_setup.assignment_roles.primary') }}
                                    <select wire:model.live="bulkPrimaryUserId"><option value="">{{ __('led_setup.labels.unchanged') }}</option>@foreach ($candidateUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>@endforeach</select>
                                </label>
                                <label>{{ __('led_setup.assignment_roles.support') }}
                                    <select wire:model.live="bulkSupportUserId"><option value="">{{ __('led_setup.labels.unchanged') }}</option>@foreach ($candidateUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>@endforeach</select>
                                </label>
                                <label>{{ __('led_setup.assignment_roles.reviewer') }}
                                    <select wire:model.live="bulkReviewerUserId"><option value="">{{ __('led_setup.labels.unchanged') }}</option>@foreach ($candidateUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>@endforeach</select>
                                </label>
                                <label class="setup-bulk-reason">{{ __('led_setup.bulk_assignment.reason') }}
                                    <input type="text" wire:model="bulkAssignmentReason" maxlength="1000" placeholder="{{ __('led_setup.bulk_assignment.reason_placeholder') }}">
                                </label>
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
                                    <button class="primary-button" type="button" wire:click="applyBulkAssignments" wire:confirm="{{ __('led_setup.confirm.apply_bulk_assignments') }}" @disabled(! $bulkAssignmentPreview['can_apply']) wire:loading.attr="disabled">{{ __('led_setup.actions.apply_bulk_assignments') }}</button>
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="setup-bulk-panel">
                        <header><div><strong>{{ __('led_setup.bulk_mapping.title') }}</strong><small>{{ __('led_setup.bulk_mapping.description') }}</small></div></header>
                        <div class="setup-bulk-body">
                            <div class="setup-bulk-selection" role="group" aria-label="{{ __('led_setup.bulk_mapping.indicator_group_label') }}">
                                @foreach ($indicatorGroups as $group)
                                    <fieldset>
                                        <legend>{{ $group['code'] }} · {{ $group['name'] }}</legend>
                                        @foreach ($group['assessments'] as $assessment)
                                            <label><input type="checkbox" wire:model.live="bulkMappingIndicatorIds" value="{{ $assessment->indicator_id }}"><span><b>{{ $assessment->indicator->indicator_number }}</b>{{ $assessment->indicator->title }}</span></label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>
                            <div class="setup-bulk-items" role="group" aria-label="{{ __('led_setup.bulk_mapping.item_group_label') }}">
                                @foreach ($projectItems->groupBy(fn ($item) => $item->criterion->code) as $criterionCode => $items)
                                    <fieldset>
                                        <legend>{{ __('led_setup.labels.criterion_with_code', ['code' => $criterionCode]) }}</legend>
                                        @foreach ($items as $item)
                                            <label><input type="checkbox" wire:model.live="bulkMappingItemIds" value="{{ $item->id }}"><span><b>{{ $item->criterion->code }}.{{ $item->number }}</b>{{ $item->title }}</span></label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>
                            <div class="setup-bulk-fields mapping">
                                <label>{{ __('led_setup.bulk_mapping.role') }}<select wire:model.live="bulkMappingRole"><option value="primary">{{ \App\Support\LocalizedLabel::mappingRole('primary') }}</option><option value="supporting">{{ \App\Support\LocalizedLabel::mappingRole('supporting') }}</option></select></label>
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
                                    <button class="primary-button" type="button" wire:click="applyBulkMappings" wire:confirm="{{ __('led_setup.confirm.apply_bulk_mappings') }}" @disabled(! $bulkMappingPreview['can_apply']) wire:loading.attr="disabled">{{ __('led_setup.actions.apply_bulk_mappings') }}</button>
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
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <form class="setup-inline-form compact" wire:submit="assignUser">
                                <select wire:model="assignmentRole" aria-label="{{ __('led_setup.assignments.role_label') }}">
                                    <option value="assessor_primary">{{ \App\Support\LocalizedLabel::assignmentRole('assessor_primary') }}</option>
                                    <option value="assessor_support">{{ \App\Support\LocalizedLabel::assignmentRole('assessor_support') }}</option>
                                    <option value="reviewer">{{ \App\Support\LocalizedLabel::assignmentRole('reviewer') }}</option>
                                </select>
                                <select wire:model="assignmentUserId" aria-label="{{ __('led_setup.assignments.select_user_label') }}">
                                    <option value="">{{ __('led_setup.assignments.select_user') }}</option>
                                    @foreach ($candidateUsers as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->roles->map(fn ($role) => \App\Support\LocalizedLabel::role($role->name))->join(', ') }}</option>
                                    @endforeach
                                </select>
                                <button class="primary-button" type="submit">{{ __('led_setup.actions.add') }}</button>
                            </form>
                        @endif
                        <div class="setup-history-list">
                            @forelse ($cycle->assignments->where('indicator_id', $selectedIndicator->id)->sortByDesc('assigned_at') as $assignment)
                                <article class="{{ $assignment->revoked_at ? 'revoked' : '' }}" wire:key="assignment-{{ $assignment->id }}">
                                    <div><strong>{{ $assignment->user->name }}</strong><small>{{ \App\Support\LocalizedLabel::assignmentRole($assignment->assignment_role) }} · {{ \App\Support\BusinessDateTime::label($assignment->assigned_at, 'd M Y H:i') }}</small></div>
                                    @if ($assignment->revoked_at)
                                        <span>{{ __('led_setup.labels.revoked') }}: {{ $assignment->revocation_reason }}</span>
                                    @elseif (in_array($cycle->status, ['draft', 'active'], true))
                                        <button type="button" wire:click="revokeAssignment({{ $assignment->id }})" wire:confirm="{{ __('led_setup.confirm.revoke_assignment') }}">{{ __('led_setup.actions.revoke') }}</button>
                                    @endif
                                </article>
                            @empty
                                <p class="setup-empty-line">{{ __('led_setup.assignments.empty') }}</p>
                            @endforelse
                        </div>
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <label class="setup-revoke-reason">{{ __('led_setup.assignments.change_reason') }}<input type="text" wire:model="assignmentRevocationReason" maxlength="1000" placeholder="{{ __('led_setup.assignments.change_reason_placeholder') }}"></label>
                        @endif
                    </section>

                    <section class="setup-operation-panel">
                        <header><div><strong>{{ __('led_setup.mappings.title') }}</strong><small>{{ __('led_setup.mappings.description') }}</small></div></header>
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <form class="setup-inline-form compact" wire:submit="createMapping">
                                <select wire:model="mappingRole" aria-label="{{ __('led_setup.mappings.role_label') }}"><option value="primary">{{ \App\Support\LocalizedLabel::mappingRole('primary') }}</option><option value="supporting">{{ \App\Support\LocalizedLabel::mappingRole('supporting') }}</option></select>
                                <select wire:model="mappingItemId" aria-label="{{ __('led_setup.mappings.select_item_label') }}">
                                    <option value="">{{ __('led_setup.mappings.select_item') }}</option>
                                    @foreach ($projectItems as $item)<option value="{{ $item->id }}">{{ $item->criterion->code }} · {{ $item->number }} · {{ str($item->title)->limit(70) }}</option>@endforeach
                                </select>
                                <button class="primary-button" type="submit">{{ __('led_setup.actions.link') }}</button>
                            </form>
                        @endif
                        <div class="setup-history-list">
                            @forelse ($cycle->itemMappings->where('indicator_id', $selectedIndicator->id)->sortByDesc('created_at') as $mapping)
                                <article class="{{ $mapping->revoked_at ? 'revoked' : '' }}" wire:key="mapping-{{ $mapping->id }}">
                                    <div><strong>{{ $mapping->item->criterion->code }} · {{ __('led_setup.labels.item_number', ['number' => $mapping->item->number]) }}</strong><small>{{ \App\Support\LocalizedLabel::mappingRole($mapping->mapping_role) }} · {{ str($mapping->item->title)->limit(75) }}</small></div>
                                    @if ($mapping->revoked_at)
                                        <span>{{ __('led_setup.labels.revoked') }}: {{ $mapping->revoke_reason }}</span>
                                    @elseif (in_array($cycle->status, ['draft', 'active'], true))
                                        <button type="button" wire:click="revokeMapping({{ $mapping->id }})" wire:confirm="{{ __('led_setup.confirm.revoke_mapping') }}">{{ __('led_setup.actions.revoke') }}</button>
                                    @endif
                                </article>
                            @empty
                                <p class="setup-empty-line">{{ __('led_setup.mappings.empty') }}</p>
                            @endforelse
                        </div>
                        @if (in_array($cycle->status, ['draft', 'active'], true))
                            <label class="setup-revoke-reason">{{ __('led_setup.mappings.revocation_reason') }}<input type="text" wire:model="mappingRevocationReason" maxlength="1000" placeholder="{{ __('led_setup.mappings.revocation_reason_placeholder') }}"></label>
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
                                <td>{{ $row['has_primary'] ? __('led_setup.labels.present') : __('led_setup.labels.missing') }}</td>
                                <td>{{ $row['has_independent_reviewer'] ? __('led_setup.labels.present') : __('led_setup.labels.missing') }}</td>
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
</div>
