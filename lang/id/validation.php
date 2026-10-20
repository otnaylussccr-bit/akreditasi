<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Validasi
    |--------------------------------------------------------------------------
    |
    | Baris bahasa berikut berisi pesan kesalahan bawaan yang digunakan oleh
    | validator. Beberapa aturan memiliki beberapa versi, seperti aturan
    | ukuran. Silakan sesuaikan pesan-pesan ini sesuai kebutuhan aplikasi.
    |
    */

    'accepted' => 'Kolom :attribute harus disetujui.',
    'accepted_if' => 'Kolom :attribute harus disetujui ketika :other bernilai :value.',
    'active_url' => 'Kolom :attribute harus berupa URL yang valid.',
    'after' => 'Kolom :attribute harus berupa tanggal setelah :date.',
    'after_or_equal' => 'Kolom :attribute harus berupa tanggal setelah atau sama dengan :date.',
    'alpha' => 'Kolom :attribute hanya boleh berisi huruf.',
    'alpha_dash' => 'Kolom :attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => 'Kolom :attribute hanya boleh berisi huruf dan angka.',
    'any_of' => 'Kolom :attribute tidak valid.',
    'array' => 'Kolom :attribute harus berupa larik.',
    'ascii' => 'Kolom :attribute hanya boleh berisi karakter alfanumerik dan simbol bita tunggal.',
    'before' => 'Kolom :attribute harus berupa tanggal sebelum :date.',
    'before_or_equal' => 'Kolom :attribute harus berupa tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => 'Kolom :attribute harus berisi antara :min dan :max item.',
        'file' => 'Ukuran :attribute harus antara :min dan :max kilobita.',
        'numeric' => 'Nilai :attribute harus antara :min dan :max.',
        'string' => 'Kolom :attribute harus terdiri atas antara :min dan :max karakter.',
    ],
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'can' => 'Kolom :attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'contains' => 'Kolom :attribute tidak memuat nilai yang diwajibkan.',
    'current_password' => 'Password salah.',
    'date' => 'Kolom :attribute harus berupa tanggal yang valid.',
    'date_equals' => 'Kolom :attribute harus berupa tanggal yang sama dengan :date.',
    'date_format' => 'Kolom :attribute harus sesuai dengan format :format.',
    'decimal' => 'Kolom :attribute harus memiliki :decimal angka di belakang koma.',
    'declined' => 'Kolom :attribute harus ditolak.',
    'declined_if' => 'Kolom :attribute harus ditolak ketika :other bernilai :value.',
    'different' => 'Kolom :attribute dan :other harus berbeda.',
    'digits' => 'Kolom :attribute harus terdiri atas :digits digit.',
    'digits_between' => 'Kolom :attribute harus terdiri atas antara :min dan :max digit.',
    'dimensions' => 'Kolom :attribute memiliki dimensi gambar yang tidak valid.',
    'distinct' => 'Kolom :attribute memiliki nilai duplikat.',
    'doesnt_contain' => 'Kolom :attribute tidak boleh memuat salah satu nilai berikut: :values.',
    'doesnt_end_with' => 'Kolom :attribute tidak boleh diakhiri dengan salah satu nilai berikut: :values.',
    'doesnt_start_with' => 'Kolom :attribute tidak boleh diawali dengan salah satu nilai berikut: :values.',
    'email' => 'Kolom :attribute harus berupa alamat email yang valid.',
    'encoding' => 'Kolom :attribute harus dikodekan dengan :encoding.',
    'ends_with' => 'Kolom :attribute harus diakhiri dengan salah satu nilai berikut: :values.',
    'enum' => 'Pilihan :attribute tidak valid.',
    'exists' => 'Pilihan :attribute tidak valid.',
    'extensions' => 'Kolom :attribute harus memiliki salah satu ekstensi berikut: :values.',
    'file' => 'Kolom :attribute harus berupa berkas.',
    'filled' => 'Kolom :attribute harus memiliki nilai.',
    'gt' => [
        'array' => 'Kolom :attribute harus berisi lebih dari :value item.',
        'file' => 'Ukuran :attribute harus lebih dari :value kilobita.',
        'numeric' => 'Nilai :attribute harus lebih dari :value.',
        'string' => 'Kolom :attribute harus terdiri atas lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => 'Kolom :attribute harus berisi minimal :value item.',
        'file' => 'Ukuran :attribute harus minimal :value kilobita.',
        'numeric' => 'Nilai :attribute harus minimal :value.',
        'string' => 'Kolom :attribute harus terdiri atas minimal :value karakter.',
    ],
    'hex_color' => 'Kolom :attribute harus berupa warna heksadesimal yang valid.',
    'image' => 'Kolom :attribute harus berupa gambar.',
    'in' => 'Pilihan :attribute tidak valid.',
    'in_array' => 'Kolom :attribute harus terdapat dalam :other.',
    'in_array_keys' => 'Kolom :attribute harus memuat setidaknya salah satu kunci berikut: :values.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'ip' => 'Kolom :attribute harus berupa alamat IP yang valid.',
    'ipv4' => 'Kolom :attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => 'Kolom :attribute harus berupa alamat IPv6 yang valid.',
    'json' => 'Kolom :attribute harus berupa string JSON yang valid.',
    'list' => 'Kolom :attribute harus berupa daftar.',
    'lowercase' => 'Kolom :attribute harus menggunakan huruf kecil.',
    'lt' => [
        'array' => 'Kolom :attribute harus berisi kurang dari :value item.',
        'file' => 'Ukuran :attribute harus kurang dari :value kilobita.',
        'numeric' => 'Nilai :attribute harus kurang dari :value.',
        'string' => 'Kolom :attribute harus terdiri atas kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => 'Kolom :attribute tidak boleh berisi lebih dari :value item.',
        'file' => 'Ukuran :attribute harus maksimal :value kilobita.',
        'numeric' => 'Nilai :attribute harus maksimal :value.',
        'string' => 'Kolom :attribute harus terdiri atas maksimal :value karakter.',
    ],
    'mac_address' => 'Kolom :attribute harus berupa alamat MAC yang valid.',
    'max' => [
        'array' => 'Kolom :attribute tidak boleh berisi lebih dari :max item.',
        'file' => 'Ukuran :attribute tidak boleh lebih dari :max kilobita.',
        'numeric' => 'Nilai :attribute tidak boleh lebih dari :max.',
        'string' => 'Kolom :attribute tidak boleh lebih dari :max karakter.',
    ],
    'max_digits' => 'Kolom :attribute tidak boleh terdiri atas lebih dari :max digit.',
    'mimes' => 'Kolom :attribute harus berupa berkas berjenis: :values.',
    'mimetypes' => 'Kolom :attribute harus berupa berkas berjenis: :values.',
    'min' => [
        'array' => 'Kolom :attribute harus berisi minimal :min item.',
        'file' => 'Ukuran :attribute harus minimal :min kilobita.',
        'numeric' => 'Nilai :attribute harus minimal :min.',
        'string' => 'Kolom :attribute harus terdiri atas minimal :min karakter.',
    ],
    'min_digits' => 'Kolom :attribute harus terdiri atas minimal :min digit.',
    'missing' => 'Kolom :attribute tidak boleh tersedia.',
    'missing_if' => 'Kolom :attribute tidak boleh tersedia ketika :other bernilai :value.',
    'missing_unless' => 'Kolom :attribute tidak boleh tersedia kecuali jika :other bernilai :value.',
    'missing_with' => 'Kolom :attribute tidak boleh tersedia ketika :values tersedia.',
    'missing_with_all' => 'Kolom :attribute tidak boleh tersedia ketika :values tersedia.',
    'multiple_of' => 'Kolom :attribute harus merupakan kelipatan :value.',
    'not_in' => 'Pilihan :attribute tidak valid.',
    'not_regex' => 'Format kolom :attribute tidak valid.',
    'numeric' => 'Kolom :attribute harus berupa angka.',
    'password' => [
        'letters' => 'Kolom :attribute harus memuat setidaknya satu huruf.',
        'mixed' => 'Kolom :attribute harus memuat setidaknya satu huruf besar dan satu huruf kecil.',
        'numbers' => 'Kolom :attribute harus memuat setidaknya satu angka.',
        'symbols' => 'Kolom :attribute harus memuat setidaknya satu simbol.',
        'uncompromised' => 'Nilai :attribute pernah muncul dalam kebocoran data. Silakan pilih :attribute yang lain.',
    ],
    'present' => 'Kolom :attribute harus tersedia.',
    'present_if' => 'Kolom :attribute harus tersedia ketika :other bernilai :value.',
    'present_unless' => 'Kolom :attribute harus tersedia kecuali jika :other bernilai :value.',
    'present_with' => 'Kolom :attribute harus tersedia ketika :values tersedia.',
    'present_with_all' => 'Kolom :attribute harus tersedia ketika :values tersedia.',
    'prohibited' => 'Kolom :attribute dilarang.',
    'prohibited_if' => 'Kolom :attribute dilarang ketika :other bernilai :value.',
    'prohibited_if_accepted' => 'Kolom :attribute dilarang ketika :other disetujui.',
    'prohibited_if_declined' => 'Kolom :attribute dilarang ketika :other ditolak.',
    'prohibited_unless' => 'Kolom :attribute dilarang kecuali jika :other termasuk dalam :values.',
    'prohibits' => 'Kolom :other tidak boleh tersedia ketika kolom :attribute tersedia.',
    'regex' => 'Format kolom :attribute tidak valid.',
    'required' => 'Kolom :attribute wajib diisi.',
    'required_array_keys' => 'Kolom :attribute harus memuat entri untuk: :values.',
    'required_if' => 'Kolom :attribute wajib diisi ketika :other bernilai :value.',
    'required_if_accepted' => 'Kolom :attribute wajib diisi ketika :other disetujui.',
    'required_if_declined' => 'Kolom :attribute wajib diisi ketika :other ditolak.',
    'required_unless' => 'Kolom :attribute wajib diisi kecuali jika :other termasuk dalam :values.',
    'required_with' => 'Kolom :attribute wajib diisi ketika :values tersedia.',
    'required_with_all' => 'Kolom :attribute wajib diisi ketika :values tersedia.',
    'required_without' => 'Kolom :attribute wajib diisi ketika :values tidak tersedia.',
    'required_without_all' => 'Kolom :attribute wajib diisi ketika semua :values tidak tersedia.',
    'same' => 'Kolom :attribute harus sama dengan :other.',
    'size' => [
        'array' => 'Kolom :attribute harus berisi :size item.',
        'file' => 'Ukuran :attribute harus :size kilobita.',
        'numeric' => 'Nilai :attribute harus :size.',
        'string' => 'Kolom :attribute harus terdiri atas :size karakter.',
    ],
    'starts_with' => 'Kolom :attribute harus diawali dengan salah satu nilai berikut: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'timezone' => 'Kolom :attribute harus berupa zona waktu yang valid.',
    'unique' => 'Nilai :attribute sudah digunakan.',
    'uploaded' => 'Berkas :attribute gagal diunggah.',
    'uppercase' => 'Kolom :attribute harus menggunakan huruf besar.',
    'url' => 'Kolom :attribute harus berupa URL yang valid.',
    'ulid' => 'Kolom :attribute harus berupa ULID yang valid.',
    'uuid' => 'Kolom :attribute harus berupa UUID yang valid.',

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Validasi Kustom
    |--------------------------------------------------------------------------
    |
    | Di sini Anda dapat menentukan pesan validasi kustom untuk atribut dengan
    | menggunakan konvensi "atribut.aturan" sebagai nama baris. Dengan begitu,
    | pesan khusus untuk kombinasi atribut dan aturan dapat ditetapkan cepat.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atribut Validasi Kustom
    |--------------------------------------------------------------------------
    |
    | Baris bahasa berikut digunakan untuk mengganti placeholder atribut dengan
    | nama yang lebih mudah dipahami, misalnya "alamat email" menggantikan
    | "email". Dengan demikian, pesan validasi menjadi lebih jelas.
    |
    */

    'attributes' => [
        'search' => 'pencarian',
        'projectId' => 'proyek',
        'cycleId' => 'siklus penilaian',
        'criterionId' => 'kriteria',
        'activeCriterionId' => 'kriteria',
        'status' => 'status',
        'workflowStatus' => 'status alur kerja',
        'event' => 'jenis aktivitas',
        'actorId' => 'pelaku',
        'dateFrom' => 'tanggal mulai',
        'dateTo' => 'tanggal akhir',
        'name' => 'nama',
        'email' => 'alamat email',
        'password' => 'password',
        'password_confirmation' => 'konfirmasi password',
        'currentPassword' => 'password saat ini',
        'newPassword' => 'password baru',
        'newPassword_confirmation' => 'konfirmasi password baru',
        'roleIds' => 'peran',
        'scopeProjectId' => 'proyek cakupan',
        'membershipRoles' => 'peran proyek',
        'picCriterionIds' => 'kriteria PIC',
        'reviewerCriterionIds' => 'kriteria reviewer',
        'resetPassword' => 'password baru',
        'resetPassword_confirmation' => 'konfirmasi password baru',
        'permissionIds' => 'izin',
        'files' => 'dokumen',
        'sourcePdf' => 'PDF sumber',
        'manifestFile' => 'berkas manifes',
        'versionId' => 'versi instrumen',
        'cycleName' => 'nama siklus',
        'indicatorId' => 'indikator',
        'assignmentUserId' => 'pengguna yang ditugaskan',
        'assignmentRole' => 'peran penugasan',
        'assignmentRevocationReason' => 'alasan perubahan penugasan',
        'mappingItemId' => 'butir akreditasi',
        'mappingRole' => 'peran pemetaan',
        'mappingRevocationReason' => 'alasan pencabutan pemetaan',
        'bulkAssignmentIndicatorIds' => 'indikator',
        'bulkPrimaryUserId' => 'asesor utama',
        'bulkSupportUserId' => 'asesor pendamping',
        'bulkReviewerUserId' => 'reviewer',
        'bulkAssignmentReason' => 'alasan penugasan',
        'bulkMappingIndicatorIds' => 'indikator',
        'bulkMappingItemIds' => 'butir akreditasi',
        'bulkMappingRole' => 'peran pemetaan',
        'decision' => 'keputusan review',
        'reviewNote' => 'catatan review',
        'correctionAttachments' => 'lampiran koreksi',
        'rubricId' => 'rubrik',
        'justification' => 'justifikasi',
        'gapAnalysis' => 'analisis kesenjangan',
        'recommendation' => 'rekomendasi',
        'decisionNote' => 'catatan keputusan',
    ],

];
