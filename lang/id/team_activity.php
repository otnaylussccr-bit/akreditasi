<?php

return [
    'meta_title' => 'Aktivitas Tim | APU Accreditation',
    'eyebrow' => 'Jejak Workflow Akreditasi',
    'title' => 'Aktivitas Tim',
    'intro' => 'Riwayat aktivitas tim :study_program dalam scope akses Anda.',
    'project' => [
        'label' => 'Project',
    ],
    'deadline' => [
        'submission' => 'Batas Akhir Pengajuan',
        'remaining' => 'Sisa Waktu',
        'days' => ':count hari',
    ],
    'filters' => [
        'aria_label' => 'Filter Aktivitas Tim',
        'activity' => 'Aktivitas',
        'all_activities' => 'Semua Aktivitas',
        'user' => 'Pengguna',
        'all_users' => 'Semua Pengguna',
        'criterion' => 'Kriteria',
        'all_criteria' => 'Semua Kriteria',
        'from' => 'Dari',
        'to' => 'Sampai',
        'search' => 'Pencarian',
        'search_placeholder' => 'Cari aktivitas, dokumen, butir, atau pengguna...',
        'reset' => 'Reset',
    ],
    'period' => [
        'range' => ':from - :to',
        'since' => 'Sejak :date',
        'until' => 'Sampai :date',
        'all' => 'Semua periode',
    ],
    'history' => [
        'title' => 'Riwayat Aktivitas',
        'showing' => 'Menampilkan :from-:to dari :count aktivitas',
        'open_item' => 'Buka :item',
        'empty_title' => 'Aktivitas tidak ditemukan',
        'empty_body' => 'Ubah filter atau tunggu aktivitas workflow baru dalam scope akses ini.',
    ],
    'pagination' => [
        'label' => 'Halaman :current dari :last',
    ],
    'summary' => [
        'title' => 'Ringkasan Aktivitas',
        'today' => [
            'label' => 'Aktivitas Hari Ini',
            'description' => 'Semua event hari ini, tidak dipengaruhi periode',
        ],
        'verified' => [
            'label' => 'Keputusan Terverifikasi',
            'description' => 'Dalam periode dan filter terpilih',
        ],
        'revisions' => [
            'label' => 'Revisi Terbuka',
            'description' => 'Status saat ini, bukan riwayat terselesaikan',
        ],
        'most_active' => [
            'label' => 'PIC Paling Aktif',
            'count' => ':count aktivitas pada periode terpilih',
            'none' => 'Belum ada',
            'empty' => 'Tidak ada aktivitas PIC yang cocok',
        ],
    ],
    'categories' => [
        'title' => 'Kegiatan per Kategori',
        'latest' => 'Terakhir: :actor pada :item',
        'empty' => 'Belum ada aktivitas pada periode ini',
    ],
    'accountability' => [
        'title' => 'Jejak Aktivitas untuk Akuntabilitas',
        'body' => 'Seluruh aktivitas workflow dicatat otomatis untuk mendukung transparansi dan traceability proses akreditasi.',
    ],
];
