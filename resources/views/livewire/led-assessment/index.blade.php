<div class="led-page">
    <header class="led-page-head">
        <div>
            <span class="eyebrow dark">{{ __('led_assessment.index.eyebrow') }}</span>
            <h1>{{ __('led_assessment.index.title') }}</h1>
            <p>{{ __('led_assessment.index.intro') }}</p>
        </div>
        <div class="led-head-actions">
            <select wire:change="selectProject($event.target.value)" aria-label="{{ __('led_assessment.index.select_project') }}">
                @foreach ($projects as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $project->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <a href="{{ route('led-assessment.dashboard', ['proyek' => $project->id]) }}" wire:navigate>{{ __('led_assessment.index.dashboard_link') }}</a>
        </div>
    </header>

    @if ($notice)
        <div class="led-notice" role="status">{{ $notice }}</div>
    @endif
    @if ($errors->any())
        <div class="led-error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if (! $cycle)
        <section class="empty-state led-empty">
            <strong>{{ __('led_assessment.index.empty_title') }}</strong>
            <p>{{ __('led_assessment.index.empty_body') }}</p>
            @can('project.manage')
                <a href="{{ route('administration.led-setup.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('led_assessment.index.open_setup') }}</a>
            @endcan
        </section>
    @else
        <section class="led-context-strip">
            <div>
                <span>{{ __('led_assessment.index.active_cycle') }}</span>
                <select wire:change="selectCycle($event.target.value)" aria-label="{{ __('led_assessment.index.select_cycle') }}">
                    @foreach ($cycles as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $cycle->id)>{{ $option->cycle_name }}</option>
                    @endforeach
                </select>
            </div>
            <div><span>{{ __('led_assessment.index.instrument_version') }}</span><strong>{{ $cycle->instrumentVersion->version_code }}</strong></div>
            <div><span>{{ __('led_assessment.index.cycle_status') }}</span><strong class="led-status status-{{ $cycle->status }}">{{ \App\Support\LocalizedLabel::status($cycle->status) }}</strong></div>
            <div><span>{{ __('led_assessment.index.indicator') }}</span><strong>{{ $assessments->count() }}</strong></div>
        </section>

        @if ($cycle->status === 'draft')
            <div class="setup-readiness-warning led-cycle-notice" role="status"><strong>{{ __('led_assessment.index.cycle_draft_title') }}</strong><p>{{ __('led_assessment.index.cycle_draft_body') }}</p></div>
        @elseif (in_array($cycle->status, ['closed', 'finalized'], true))
            <div class="workflow-lock-notice led-cycle-notice" role="status"><strong>{{ __('led_assessment.index.cycle_locked_title', ['status' => \App\Support\LocalizedLabel::status($cycle->status)]) }}</strong><span>{{ __('led_assessment.index.cycle_locked_body') }}</span></div>
        @endif

        <div class="led-workspace">
            <aside class="led-indicator-panel">
                <div class="led-panel-title">
                    <div><span>{{ __('led_assessment.index.indicator_list') }}</span><strong>{{ trans_choice('led_assessment.index.items', $assessments->count(), ['count' => $assessments->count()]) }}</strong></div>
                </div>
                <div class="led-indicator-list">
                    @forelse ($assessmentGroups as $group)
                        <details class="led-indicator-group" @if ($group['assessments']->contains('id', $selectedAssessment?->id)) open @endif>
                            <summary><span><b>{{ $group['code'] }}</b>{{ $group['name'] }}</span><small>{{ trans_choice('led_assessment.index.indicators', $group['assessments']->count(), ['count' => $group['assessments']->count()]) }}</small></summary>
                            <div>
                                @foreach ($group['assessments'] as $assessment)
                                    @php
                                        $revision = $assessment->currentWorkingRevision;
                                        $approved = $assessment->currentApprovedRevision;
                                        $evidenceCount = $revision?->evidence?->count() ?? 0;
                                        $displayedScore = $approved?->rubric?->internal_score ?? $revision?->rubric?->internal_score;
                                        $scoreTone = match ((float) $displayedScore) {
                                            4.0 => '4',
                                            3.0 => '3',
                                            2.0 => '2',
                                            1.0 => '1',
                                            default => 'neutral',
                                        };
                                    @endphp
                                    <button
                                        type="button"
                                        wire:key="led-assessment-{{ $assessment->id }}"
                                        wire:click="selectAssessment({{ $assessment->id }})"
                                        class="{{ $selectedAssessment?->id === $assessment->id ? 'active' : '' }}"
                                        aria-current="{{ $selectedAssessment?->id === $assessment->id ? 'true' : 'false' }}"
                                    >
                                        <span class="led-number">{{ str_pad((string) $assessment->indicator->indicator_number, 2, '0', STR_PAD_LEFT) }}</span>
                                        <span class="led-indicator-copy">
                                            <strong>{{ $assessment->indicator->title }}</strong>
                                            <small><i>{{ \App\Support\LocalizedLabel::status($approved ? 'approved' : $revision?->status) }}</i><i>{{ $evidenceCount ? trans_choice('led_assessment.index.evidence_count', $evidenceCount, ['count' => $evidenceCount]) : __('led_assessment.index.no_evidence') }}</i></small>
                                        </span>
                                        <span class="led-score led-score-tone score-tone-{{ $scoreTone }}" data-score-tone="{{ $scoreTone }}"><x-led-score-value :score="$displayedScore" /></span>
                                    </button>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <div class="empty-state">{{ __('led_assessment.index.no_assessment') }}</div>
                    @endforelse
                </div>
            </aside>

            <main class="led-editor">
                @if ($selectedAssessment)
                    <header class="led-editor-head">
                        <div class="led-indicator-identity">
                            <span>{{ str_pad((string) $selectedAssessment->indicator->indicator_number, 2, '0', STR_PAD_LEFT) }}</span>
                            <div>
                                <h2>{{ $selectedAssessment->indicator->title }}</h2>
                                <p>{{ __('led_assessment.index.criterion_source', ['code' => $selectedAssessment->indicator->criterion_code, 'source' => $cycle->instrumentVersion->source_title]) }}</p>
                            </div>
                        </div>
                        <span class="led-status status-{{ $workingRevision?->status ?? 'not_started' }}">
                            {{ \App\Support\LocalizedLabel::status($workingRevision?->status) }}
                        </span>
                    </header>

                    <section class="led-section">
                        <div class="led-section-heading"><span>1</span><div><strong>{{ __('led_assessment.index.score_heading') }}</strong><small>{{ __('led_assessment.index.score_help') }}</small></div></div>
                        <div class="led-rubric-grid">
                            @foreach ($rubrics as $rubric)
                                @php
                                    $scoreTone = match ((float) $rubric->internal_score) {
                                        4.0 => '4',
                                        3.0 => '3',
                                        2.0 => '2',
                                        1.0 => '1',
                                        default => 'neutral',
                                    };
                                @endphp
                                <label class="led-rubric-card led-score-tone score-tone-{{ $scoreTone }} {{ $rubricId === $rubric->id ? 'selected' : '' }} {{ ! $canEdit || ($workingRevision && ! in_array($workingRevision->status, ['draft', 'revision_requested'], true)) ? 'read-only' : '' }}" data-score-tone="{{ $scoreTone }}">
                                    <input type="radio" wire:model.live="rubricId" value="{{ $rubric->id }}" @disabled(! $canEdit || ($workingRevision && ! in_array($workingRevision->status, ['draft', 'revision_requested'], true)))>
                                    <div class="led-rubric-header">
                                        <span class="led-rubric-score"><x-led-score-value :score="$rubric->internal_score" /></span>
                                        <strong class="led-rubric-label">{{ $rubric->category_label }}</strong>
                                    </div>
                                    <small>{{ $rubric->description }}</small>
                                    @if ($rubric->page_start)<em>{{ __('led_assessment.index.source_page', ['pages' => $rubric->page_start.($rubric->page_end && $rubric->page_end !== $rubric->page_start ? '–'.$rubric->page_end : '')]) }}</em>@endif
                                </label>
                            @endforeach
                        </div>
                    </section>

                    <div class="led-form-grid">
                        <section class="led-section">
                            <div class="led-section-heading"><span>2</span><div><strong>{{ __('led_assessment.index.narrative_heading') }}</strong><small>{{ __('led_assessment.index.narrative_help') }}</small></div></div>
                            <textarea wire:model="justification" rows="6" @disabled(! $canEdit || ($workingRevision && ! in_array($workingRevision->status, ['draft', 'revision_requested'], true))) placeholder="{{ __('led_assessment.index.narrative_placeholder') }}"></textarea>
                        </section>
                        <section class="led-section led-recommendation">
                            <div class="led-section-heading"><span>3</span><div><strong>{{ __('led_assessment.index.recommendation_heading') }}</strong><small>{{ __('led_assessment.index.recommendation_help') }}</small></div></div>
                            <label>{{ __('led_assessment.index.gap') }}<textarea wire:model="gapAnalysis" rows="3" @disabled(! $canEdit || ($workingRevision && ! in_array($workingRevision->status, ['draft', 'revision_requested'], true)))></textarea></label>
                            <label>{{ __('led_assessment.index.recommendation') }}<textarea wire:model="recommendation" rows="3" @disabled(! $canEdit || ($workingRevision && ! in_array($workingRevision->status, ['draft', 'revision_requested'], true)))></textarea></label>
                        </section>
                    </div>

                    <section class="led-section">
                        <div class="led-section-heading"><span>4</span><div><strong>{{ __('led_assessment.index.evidence_heading') }}</strong><small>{{ __('led_assessment.index.evidence_help') }}</small></div></div>
                        <div class="led-evidence-list">
                            @forelse ($workingRevision?->evidence ?? [] as $evidence)
                                <div><span>{{ strtoupper($evidence->document->extension) }}</span><p><strong>{{ $evidence->document->original_name }}</strong><small>{{ __('led_assessment.index.linked_evidence_meta', ['item' => $evidence->document->requirement->item->criterion->code.'.'.$evidence->document->requirement->item->number, 'status' => \App\Support\LocalizedLabel::status('verified'), 'revision' => $evidence->verificationSubmission->revision_number, 'hash' => substr($evidence->document_sha256, 0, 12)]) }}</small></p><b>{{ \App\Support\LocalizedLabel::status('verified') }}</b></div>
                            @empty
                                <p>{{ __('led_assessment.index.no_linked_evidence') }}</p>
                            @endforelse
                        </div>
                        @if ($canEdit)
                            @if (! $workingRevision)
                                <div class="led-evidence-empty"><strong>{{ __('led_assessment.index.save_draft_first') }}</strong><span>{{ __('led_assessment.index.draft_required_for_evidence') }}</span></div>
                            @elseif ($workingRevision->status === 'draft')
                                <div class="led-repository-picker">
                                    @forelse ($repositoryDocuments as $document)
                                        @php $verifiedSubmission = $document->reviewSubmissions->first(); @endphp
                                        <button type="button" wire:click="linkEvidence({{ $document->id }}, {{ $verifiedSubmission->id }})">
                                            <span>+</span><b>{{ $document->original_name }}</b><small>{{ __('led_assessment.index.available_evidence_meta', ['status' => \App\Support\LocalizedLabel::status('verified'), 'item' => $document->requirement->item->criterion->code.'.'.$document->requirement->item->number]) }}</small>
                                        </button>
                                    @empty
                                        <div class="led-evidence-empty"><strong>{{ __('led_assessment.index.no_eligible_evidence') }}</strong><span>{{ __('led_assessment.index.eligible_evidence_help') }}</span></div>
                                    @endforelse
                                </div>
                            @endif
                        @endif
                    </section>

                    @if ($workingRevision && in_array($workingRevision->status, ['submitted', 'in_review'], true))
                        @can('review', $workingRevision)
                            <section class="led-decision-box">
                                <label>{{ __('led_assessment.index.reviewer_note') }}<textarea wire:model="decisionNote" rows="3" placeholder="{{ __('led_assessment.index.decision_placeholder') }}"></textarea></label>
                            </section>
                        @endcan
                    @endif

                    <footer class="led-editor-actions">
                        <span>{{ __('led_assessment.index.active_revision', ['revision' => $workingRevision?->revision_number ? 'r'.$workingRevision->revision_number : __('led_assessment.index.revision_not_created')]) }}</span>
                        <div>
                            @can('update', $selectedAssessment)
                                @if (! $workingRevision || $workingRevision->status === 'draft' || $workingRevision->status === 'revision_requested')
                                     <button type="button" class="secondary" wire:click="saveDraft">{{ __('led_assessment.index.actions.save_draft') }}</button>
                                @endif
                            @endcan
                            @if ($workingRevision)
                                @can('submit', $workingRevision)
                                    @if ($workingRevision->status === 'draft')<button type="button" class="primary" wire:click="submit">{{ __('led_assessment.index.actions.submit') }}</button>@endif
                                @endcan
                                @can('review', $workingRevision)
                                    @if ($workingRevision->status === 'submitted')<button type="button" class="primary" wire:click="startReview">{{ __('led_assessment.index.actions.review') }}</button>@endif
                                    @if ($workingRevision->status === 'in_review')<button type="button" class="danger" wire:click="requestRevision">{{ __('led_assessment.index.actions.request_revision') }}</button>@endif
                                @endcan
                                @can('approve', $workingRevision)
                                    @if ($workingRevision->status === 'in_review')<button type="button" class="primary" wire:click="approve">{{ __('led_assessment.index.actions.approve') }}</button>@endif
                                @endcan
                            @endif
                        </div>
                    </footer>
                @endif
            </main>
        </div>
    @endif
</div>
