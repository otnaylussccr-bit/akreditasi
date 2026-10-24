<div class="led-page relationship-explorer">
    <header class="led-page-head">
        <div><span class="eyebrow dark">{{ __('led_explorer.eyebrow') }}</span><h1>{{ __('led_explorer.title') }}</h1><p>{{ __('led_explorer.intro') }}</p></div>
        <div class="led-head-actions">
            <select wire:change="selectProject($event.target.value)" aria-label="{{ __('led_explorer.select_project') }}">@foreach ($projects as $option)<option value="{{ $option->id }}" @selected($option->id === $project->id)>{{ $option->name }}</option>@endforeach</select>
            @can('project.manage')<a href="{{ route('administration.led-setup.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('led_explorer.open_setup') }}</a>@endcan
        </div>
    </header>

    @if (! $cycle)
        <section class="empty-state led-empty"><strong>{{ __('led_explorer.empty_title') }}</strong><p>{{ __('led_explorer.empty_body') }}</p></section>
    @else
        <section class="led-context-strip">
            <div><span>{{ __('led_explorer.cycle') }}</span><select wire:change="selectCycle($event.target.value)" aria-label="{{ __('led_explorer.select_cycle') }}">@foreach ($cycles as $option)<option value="{{ $option->id }}" @selected($option->id === $cycle->id)>{{ $option->cycle_name }}</option>@endforeach</select></div>
            <div><span>{{ __('led_explorer.instrument') }}</span><strong>{{ $cycle->instrumentVersion->version_code }}</strong><small>{{ $cycle->instrumentVersion->source_reference }}</small></div>
            <div><span>{{ __('led_explorer.cycle_status') }}</span><strong class="led-status status-{{ $cycle->status }}">{{ \App\Support\LocalizedLabel::status($cycle->status) }}</strong></div>
            <div><span>{{ __('led_explorer.numbering') }}</span><small>{{ __('led_explorer.numbering_help') }}</small></div>
        </section>

        <section class="explorer-summary" aria-label="{{ __('led_explorer.summary') }}">
            <article><span>{{ __('led_explorer.assessable_indicators') }}</span><strong>{{ $summary['indicators'] }}</strong></article>
            <article><span>{{ __('led_explorer.mapped_indicators') }}</span><strong>{{ $summary['mapped_indicators'] }}</strong></article>
            <article><span>{{ __('led_explorer.unmapped_indicators') }}</span><strong>{{ $summary['unmapped_indicators'] }}</strong></article>
            <article><span>{{ __('led_explorer.active_relationships') }}</span><strong>{{ $summary['relationships'] }}</strong></article>
            <article><span>{{ __('led_explorer.verified_submissions') }}</span><strong>{{ $summary['verified_submissions'] ?? __('led_explorer.unavailable') }}</strong></article>
            <article><span>{{ __('led_explorer.linked_evidence') }}</span><strong>{{ $summary['linked_evidence'] ?? __('led_explorer.unavailable') }}</strong></article>
        </section>

        <section class="explorer-controls">
            <div class="explorer-tabs" role="tablist" aria-label="{{ __('led_explorer.view_label') }}">
                <button type="button" role="tab" wire:click="setViewMode('indicator')" aria-selected="{{ $viewMode === 'indicator' ? 'true' : 'false' }}" class="{{ $viewMode === 'indicator' ? 'active' : '' }}">{{ __('led_explorer.indicator_view') }}</button>
                <button type="button" role="tab" wire:click="setViewMode('item')" aria-selected="{{ $viewMode === 'item' ? 'true' : 'false' }}" class="{{ $viewMode === 'item' ? 'active' : '' }}">{{ __('led_explorer.item_view') }}</button>
            </div>
            <label><span>{{ __('led_explorer.criterion') }}</span><select wire:model.live="criterionFilter"><option value="">{{ __('led_explorer.all_criteria') }}</option>@foreach ($criteria as $criterion)<option value="{{ $criterion }}">{{ $criterion }}</option>@endforeach</select></label>
            <label><span>{{ __('led_explorer.search') }}</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $viewMode === 'indicator' ? __('led_explorer.search_indicator') : __('led_explorer.search_item') }}"></label>
        </section>
        @if ($viewMode === 'indicator')
            <div class="explorer-workspace">
                <section class="explorer-list" aria-label="{{ __('led_explorer.indicator_view') }}">
                    @forelse ($filteredIndicators as $row)
                        <button type="button" wire:click="selectIndicator({{ $row['assessment_id'] }})" class="{{ $selectedIndicator && $selectedIndicator['assessment_id'] === $row['assessment_id'] ? 'active' : '' }}">
                            <span class="led-number">{{ $row['number'] }}</span><span><small>{{ $row['criterion'] }}</small><strong>{{ $row['title'] }}</strong><i>{{ trans_choice('led_explorer.relationship_count', $row['mappings']->count(), ['count' => $row['mappings']->count()]) }}</i></span>
                        </button>
                    @empty <div class="empty-state">{{ __('led_explorer.no_indicators') }}</div> @endforelse
                </section>
                <section class="explorer-detail">
                    @if ($selectedIndicator)
                        <header><span>{{ __('led_explorer.indicator') }} {{ $selectedIndicator['number'] }}</span><h2>{{ $selectedIndicator['title'] }}</h2><p>{{ $selectedIndicator['description'] ?: __('led_explorer.no_description') }}</p><strong class="led-status status-{{ $selectedIndicator['assessment_status'] ?? 'not_started' }}">{{ \App\Support\LocalizedLabel::status($selectedIndicator['assessment_status']) }}</strong></header>
                        <div class="explorer-assignees">
                            @foreach (['assessor_primary', 'assessor_support', 'reviewer'] as $role)<div><span>{{ \App\Support\LocalizedLabel::assignmentRole($role) }}</span><strong>{{ $selectedIndicator['assignments']->get($role, collect())->join(', ') ?: __('led_explorer.unassigned') }}</strong></div>@endforeach
                        </div>
                        <h3>{{ __('led_explorer.active_mappings') }}</h3><p class="explorer-mapping-notice">{{ __('led_explorer.mapping_notice') }}</p>
                        @forelse ($selectedIndicator['mappings'] as $mapping)
                            <article class="explorer-relation"><button type="button" wire:click="openMappedItem({{ $mapping['id'] }})"><strong>{{ $mapping['code'] }}</strong><span>{{ $mapping['title'] }}</span><small>{{ \App\Support\LocalizedLabel::mappingRole($mapping['role']) }}</small></button><div><i class="evidence-state state-{{ $mapping['state'] }}">{{ __('led_explorer.evidence_states.'.$mapping['state']) }}</i><small class="explorer-counts">{{ __('led_explorer.evidence_counts', ['documents' => $mapping['document_count'] ?? __('led_explorer.unavailable'), 'submissions' => $mapping['verified_submission_count'] ?? __('led_explorer.unavailable'), 'linked' => $mapping['linked_evidence_count'] ?? __('led_explorer.unavailable')]) }}</small></div></article>
                        @empty <div class="empty-state"><strong>{{ __('led_explorer.no_mapping_title') }}</strong><p>{{ __('led_explorer.no_mapping_body') }}</p></div> @endforelse
                    @endif
                </section>
            </div>
        @else
            <div class="explorer-workspace">
                <section class="explorer-list" aria-label="{{ __('led_explorer.item_view') }}">
                    @forelse ($filteredItems->groupBy('criterion_code') as $criterionCode => $items)
                        <div class="explorer-item-group"><strong>{{ $criterionCode }} · {{ $items->first()['criterion_name'] }}</strong></div>
                        @foreach ($items as $row)<button type="button" wire:click="selectItem({{ $row['id'] }})" class="{{ $selectedItem && $selectedItem['id'] === $row['id'] ? 'active' : '' }}"><span class="led-number">{{ $row['code'] }}</span><span><small>{{ $row['criterion_name'] }}</small><strong>{{ $row['title'] }}</strong><i>{{ trans_choice('led_explorer.relationship_count', $row['indicators']->count(), ['count' => $row['indicators']->count()]) }}</i></span></button>@endforeach
                    @empty <div class="empty-state">{{ __('led_explorer.no_items') }}</div> @endforelse
                </section>
                <section class="explorer-detail">
                    @if ($selectedItem)
                        <header><span>{{ __('led_explorer.item') }} {{ $selectedItem['code'] }}</span><h2>{{ $selectedItem['title'] }}</h2><p>{{ $selectedItem['criterion_name'] }}</p><i class="evidence-state state-{{ $selectedItem['state'] }}">{{ __('led_explorer.evidence_states.'.$selectedItem['state']) }}</i></header>
                        @if ($canViewDocuments)<p class="explorer-workflow"><strong>{{ __('led_explorer.workflow_status') }}:</strong> {{ $selectedItem['workflow']->map(fn ($status) => \App\Support\LocalizedLabel::status($status))->join(', ') ?: __('led_explorer.unavailable') }}</p>@else<p class="explorer-restricted">{{ __('led_explorer.document_restricted') }}</p>@endif
                        <h3>{{ __('led_explorer.linked_indicators') }}</h3>
                        @forelse ($selectedItem['indicators'] as $indicator)<article class="explorer-relation"><button type="button" wire:click="openMappedIndicator({{ $indicator['id'] }})"><strong>{{ __('led_explorer.indicator') }} {{ $indicator['number'] }}</strong><span>{{ $indicator['title'] }}</span></button><small>{{ \App\Support\LocalizedLabel::mappingRole($indicator['role']) }}</small></article>@empty<div class="empty-state"><strong>{{ __('led_explorer.unmapped_item') }}</strong><p>{{ __('led_explorer.unmapped_item_body') }}</p></div>@endforelse
                    @endif
                </section>
            </div>
        @endif
    @endif
</div>
