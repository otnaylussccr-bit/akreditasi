<?php

namespace App\Livewire\TeamActivity;

use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationWorkflowActivityService;
use App\Support\BusinessDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Aktivitas Tim | APU Accreditation')]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 10;

    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    #[Url(as: 'aktivitas', except: '')]
    public string $event = '';

    #[Url(as: 'pengguna', except: null)]
    public ?int $actorId = null;

    #[Url(as: 'kriteria', except: null)]
    public ?int $criterionId = null;

    #[Url(as: 'dari', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'sampai', except: '')]
    public string $dateTo = '';

    #[Url(as: 'cari', except: '')]
    public string $search = '';

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationWorkflowActivityService $activity,
        AccreditationProjectContextService $context,
    ): void {
        $user = Auth::user();
        abort_unless($activity->canView($user), 403);
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $context->resolve($projects, $this->projectId);
        abort_unless($project, 403);
        $this->projectId = $project->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'event',
            'actorId',
            'criterionId',
            'dateFrom',
            'dateTo',
            'search',
        ], true)) {
            $this->resetPage();
        }
    }

    public function selectProject(
        int $projectId,
        AccreditationAuthorizationService $authorization,
        AccreditationWorkflowActivityService $activity,
        AccreditationProjectContextService $context,
    ): void {
        abort_unless($activity->canView(Auth::user()), 403);
        $this->projectId = $context->select($this->authorizedProjects($authorization), $projectId)->id;
        $this->resetFilters();
    }

    public function resetFilters(): void
    {
        $this->reset(['event', 'actorId', 'criterionId', 'dateFrom', 'dateTo', 'search']);
        $this->resetPage();
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationWorkflowActivityService $activity,
        AccreditationProjectContextService $context,
    ) {
        $user = Auth::user();
        abort_unless($activity->canView($user), 403);
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        $criteria = $authorization->accessibleCriteria($user, $project)
            ->select(['id', 'project_id', 'code', 'name', 'position'])
            ->orderBy('position')
            ->get();

        $authorizedQuery = $activity->query($user, $project);
        $baseQuery = $activity->query($user, $project, $this->criterionId);
        $actorOptions = User::query()
            ->select(['id', 'name'])
            ->whereIn('id', (clone $baseQuery)->select('actor_id')->whereNotNull('actor_id'))
            ->orderBy('name')
            ->get();
        $this->validateSelections($criteria, $actorOptions);
        $filteredQuery = $activity->filteredQuery($baseQuery, $project, $this->filters());
        $paginator = (clone $filteredQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
        $paginator->setCollection($activity->project($paginator->getCollection(), $project));
        $today = BusinessDateTime::today()->toDateString();
        $todayStart = BusinessDateTime::startOfDayUtc($today);
        $todayEnd = BusinessDateTime::endOfDayUtc($today);
        $todayCount = (clone $authorizedQuery)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();
        $verifiedCount = (clone $filteredQuery)
            ->where('event', 'document.verified')
            ->distinct('subject_id')
            ->count('subject_id');

        return view('livewire.team-activity.index', [
            'projects' => $projects,
            'project' => $project,
            'criteria' => $criteria,
            'actorOptions' => $actorOptions,
            'eventOptions' => AccreditationWorkflowActivityService::EVENTS,
            'activities' => $paginator,
            'todayCount' => $todayCount,
            'verifiedCount' => $verifiedCount,
            'openRevisionCount' => $activity->openRevisionCount($user, $project),
            'mostActivePic' => $activity->mostActivePic($filteredQuery),
            'categories' => $activity->categorySummaries($filteredQuery, $project),
            'periodLabel' => $this->periodLabel(),
        ]);
    }

    private function authorizedProjects(AccreditationAuthorizationService $authorization): Collection
    {
        return $authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
            ->get();
    }

    private function filters(): array
    {
        return [
            'event' => $this->event,
            'actor_id' => $this->actorId,
            'date_from' => $this->dateFrom !== '' ? $this->dateFrom : null,
            'date_to' => $this->dateTo !== '' ? $this->dateTo : null,
            'search' => $this->search,
        ];
    }

    private function validateSelections(Collection $criteria, Collection $actorOptions): void
    {
        abort_unless(
            $this->event === '' || array_key_exists($this->event, AccreditationWorkflowActivityService::EVENTS),
            403,
        );
        abort_unless($this->criterionId === null || $criteria->contains('id', $this->criterionId), 403);
        abort_unless($this->actorId === null || $actorOptions->contains('id', $this->actorId), 403);
        abort_unless($this->validDate($this->dateFrom) && $this->validDate($this->dateTo), 403);
        abort_unless(
            $this->dateFrom === '' || $this->dateTo === '' || $this->dateFrom <= $this->dateTo,
            403,
        );
    }

    private function validDate(string $date): bool
    {
        if ($date === '') {
            return true;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date)->format('Y-m-d') === $date;
        } catch (\Throwable) {
            return false;
        }
    }

    private function periodLabel(): string
    {
        return match (true) {
            $this->dateFrom !== '' && $this->dateTo !== '' => Carbon::parse($this->dateFrom)->format('d M Y').' - '.Carbon::parse($this->dateTo)->format('d M Y'),
            $this->dateFrom !== '' => 'Sejak '.Carbon::parse($this->dateFrom)->format('d M Y'),
            $this->dateTo !== '' => 'Sampai '.Carbon::parse($this->dateTo)->format('d M Y'),
            default => 'Semua periode',
        };
    }
}
