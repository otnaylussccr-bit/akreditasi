<?php

return [
    'meta_title' => 'Team Activity | APU Accreditation',
    'eyebrow' => 'Accreditation Workflow Trail',
    'title' => 'Team Activity',
    'intro' => 'Team activity history for :study_program within your access scope.',
    'project' => [
        'label' => 'Project',
    ],
    'deadline' => [
        'submission' => 'Submission Deadline',
        'remaining' => 'Time Remaining',
        'days' => '{1} 1 day|[0,*] :count days',
    ],
    'filters' => [
        'aria_label' => 'Team activity filters',
        'activity' => 'Activity',
        'all_activities' => 'All Activities',
        'user' => 'User',
        'all_users' => 'All Users',
        'criterion' => 'Criterion',
        'all_criteria' => 'All Criteria',
        'from' => 'From',
        'to' => 'To',
        'search' => 'Search',
        'search_placeholder' => 'Search activities, documents, items, or users...',
        'reset' => 'Reset',
    ],
    'period' => [
        'range' => ':from - :to',
        'since' => 'Since :date',
        'until' => 'Through :date',
        'all' => 'All periods',
    ],
    'history' => [
        'title' => 'Activity History',
        'showing' => '{1} Showing :from-:to of :count activity|[0,*] Showing :from-:to of :count activities',
        'open_item' => 'Open :item',
        'empty_title' => 'No activities found',
        'empty_body' => 'Change the filters or wait for new workflow activity within this access scope.',
    ],
    'pagination' => [
        'label' => 'Page :current of :last',
    ],
    'summary' => [
        'title' => 'Activity Summary',
        'today' => [
            'label' => 'Activity Today',
            'description' => 'All events today, unaffected by the selected period',
        ],
        'verified' => [
            'label' => 'Verified Decisions',
            'description' => 'Within the selected period and filters',
        ],
        'revisions' => [
            'label' => 'Open Revisions',
            'description' => 'Current status, not completed history',
        ],
        'most_active' => [
            'label' => 'Most Active PIC',
            'count' => '{1} 1 activity in the selected period|[0,*] :count activities in the selected period',
            'none' => 'None yet',
            'empty' => 'No matching PIC activity',
        ],
    ],
    'categories' => [
        'title' => 'Activity by Category',
        'latest' => 'Latest: :actor on :item',
        'empty' => 'No activity in this period',
    ],
    'accountability' => [
        'title' => 'Activity Trail for Accountability',
        'body' => 'All workflow activity is recorded automatically to support transparency and traceability throughout the accreditation process.',
    ],
];
