# Office Document Preview

Fitur ini membuat PDF preview privat untuk dokumen DOCX dan XLSX. Konversi berjalan asynchronous melalui queue dan tidak mengubah file asli, metadata dokumen, submission snapshot, status workflow, assignment, atau role.

Fitur default-nya nonaktif. Jangan mengaktifkannya sebelum instalasi LibreOffice, isolasi jaringan proses converter, batas resource, dan font host ditinjau oleh Project Owner atau administrator infrastruktur.

## Alur Data

1. Reviewer membuka dokumen DOCX atau XLSX yang sudah menjadi bagian dari snapshot submission.
2. Aplikasi memeriksa otorisasi reviewer, membership snapshot, checksum file asli, status converter, dan cache.
3. Cache miss membuat job `GenerateOfficeDocumentPreview` pada queue `office-preview`.
4. Worker menyalin file ke temporary workspace acak dan memvalidasi struktur OOXML sebelum menjalankan LibreOffice headless.
5. PDF tervalidasi dipublikasikan ke private disk pada path berikut:

   ```text
   accreditation-preview-cache/documents/{document_id}/{sha256}/{profile_version}/preview.pdf
   ```

6. Browser mengambil PDF melalui endpoint terotorisasi dengan `Content-Disposition: inline`.

PDF, JPEG, JPG, dan PNG tetap menggunakan viewer browser existing tanpa converter. Format DOC, XLS, BMP, TIFF, dan format lain tetap menggunakan fallback download.

## Prasyarat

- PHP harus menyediakan `proc_open`, `proc_get_status`, `proc_terminate`, dan `proc_close`.
- Ekstensi PHP `zip`, `simplexml`, dan `libxml` harus aktif.
- Disk `local` harus menunjuk ke storage privat, tidak dipublikasikan oleh web server, dan memiliki `serve=false`.
- Tabel queue, failed job, cache, dan cache lock harus tersedia untuk konfigurasi database default.
- Worker harus berjalan dengan user non-root dan hanya memiliki write access ke storage aplikasi serta temporary directory yang ditentukan.
- Process timeout harus lebih rendah daripada job timeout, dan job timeout harus lebih rendah daripada queue `retry_after`.
- Network egress LibreOffice harus diblokir oleh kontrol OS atau container. Environment flag aplikasi tidak membuat isolasi jaringan.
- Environment child process dibersihkan dari secret aplikasi, tetapi converter tetap harus diperlakukan sebagai proses untrusted dan dikurung oleh OS/container.

## Windows

1. Dapatkan persetujuan Project Owner sebelum memasang LibreOffice pada host aplikasi.
2. Instal LibreOffice 64-bit dari sumber resmi dan pin versi yang disetujui.
3. Jalankan worker dengan service account lokal berprivilege minimum. Jangan memakai akun administrator atau akun interaktif pengguna.
4. Buat Windows Defender Firewall outbound rules yang memblokir jaringan untuk executable LibreOffice, termasuk `soffice.exe` dan `soffice.bin` pada direktori instalasi.
5. Batasi service account dan temporary root agar tidak dapat menulis ke direktori aplikasi selain `storage` dan `bootstrap/cache`.
6. Terapkan memory/CPU/process limits melalui mekanisme service supervisor atau Windows Job Object yang disetujui organisasi.
7. Pasang font yang dipakai dokumen institusi, lalu validasi fidelity dengan corpus non-rahasia.

Contoh konfigurasi lokal setelah kontrol host selesai:

```dotenv
DOCUMENT_PREVIEW_ENABLED=true
DOCUMENT_PREVIEW_EXECUTABLE="C:/Program Files/LibreOffice/program/soffice.exe"
DOCUMENT_PREVIEW_NETWORK_ISOLATED=true
DOCUMENT_PREVIEW_QUEUE=office-preview
DOCUMENT_PREVIEW_PROCESS_TIMEOUT=60
DOCUMENT_PREVIEW_JOB_TIMEOUT=75
DOCUMENT_PREVIEW_JOB_TRIES=3
DOCUMENT_PREVIEW_PROFILE_VERSION=libreoffice-v1
```

Jika executable tidak diisi, aplikasi mencoba lokasi instalasi Windows yang umum. Path eksplisit lebih dianjurkan untuk production agar upgrade tidak mengganti binary tanpa review.

## Linux

1. Dapatkan persetujuan Project Owner sebelum memasang paket LibreOffice Writer dan Calc pada host atau image worker.
2. Pin versi package/image dan jalankan worker `office-preview` terpisah dari worker aplikasi umum.
3. Jalankan worker sebagai user non-root tanpa home directory bersama.
4. Blokir network egress proses LibreOffice dengan AppArmor, SELinux, seccomp, firewall per-cgroup, atau container policy yang sudah diuji.
5. Terapkan memory dan CPU limit melalui systemd/cgroup atau orchestrator.
6. Gunakan filesystem read-only selain path storage aplikasi dan temporary root yang dibutuhkan.
7. Pasang font institusi secara eksplisit di image/host yang sama.

Mulai dengan satu worker `office-preview`. Tambah concurrency hanya setelah profiling CPU, memory, dan conversion corpus disetujui; lock aplikasi mencegah duplikasi per dokumen tetapi bukan batas concurrency global.

Contoh environment setelah sandbox diverifikasi:

```dotenv
DOCUMENT_PREVIEW_ENABLED=true
DOCUMENT_PREVIEW_EXECUTABLE=/usr/bin/libreoffice
DOCUMENT_PREVIEW_NETWORK_ISOLATED=true
DOCUMENT_PREVIEW_QUEUE=office-preview
DOCUMENT_PREVIEW_PROCESS_TIMEOUT=60
DOCUMENT_PREVIEW_JOB_TIMEOUT=75
DOCUMENT_PREVIEW_JOB_TRIES=3
DOCUMENT_PREVIEW_PROFILE_VERSION=libreoffice-v1
```

Contoh worker:

```bash
php artisan queue:work database --queue=office-preview --sleep=1 --tries=3 --timeout=75
```

Jangan memakai `RestrictAddressFamilies` pada seluruh worker jika koneksi queue/database membutuhkan TCP. Dalam konfigurasi itu, isolasi harus diterapkan khusus kepada executable LibreOffice atau converter harus dipindahkan ke sandbox/container terpisah.

## macOS Development

Aplikasi mengenali path default `/Applications/LibreOffice.app/Contents/MacOS/soffice`. macOS hanya ditujukan untuk development atau UAT lokal. Biarkan fitur nonaktif jika tidak tersedia mekanisme sandbox jaringan yang telah disetujui.

## Konfigurasi

| Variable | Default | Keterangan |
| --- | --- | --- |
| `DOCUMENT_PREVIEW_ENABLED` | `false` | Master switch. |
| `DOCUMENT_PREVIEW_EXECUTABLE` | kosong | Path absolut LibreOffice/soffice; jika kosong memakai discovery terkontrol. |
| `DOCUMENT_PREVIEW_NETWORK_ISOLATED` | `false` | Pernyataan operator bahwa isolasi OS/container sudah aktif. Bukan mekanisme isolasi. |
| `DOCUMENT_PREVIEW_QUEUE` | `office-preview` | Nama queue job conversion. |
| `DOCUMENT_PREVIEW_PROCESS_TIMEOUT` | `60` | Batas proses LibreOffice dalam detik. |
| `DOCUMENT_PREVIEW_JOB_TIMEOUT` | `75` | Batas Laravel job dalam detik. |
| `DOCUMENT_PREVIEW_JOB_TRIES` | `3` | Maksimum attempt job. |
| `DOCUMENT_PREVIEW_JOB_BACKOFF` | `10,30` | Backoff antar-attempt dalam detik. |
| `DOCUMENT_PREVIEW_PROFILE_VERSION` | `libreoffice-v1` | Bagian cache key; naikkan saat versi converter/profile berubah. |
| `DOCUMENT_PREVIEW_TEMP_ROOT` | `storage/app/document-preview-temporary` | Temporary workspace root. |
| `DOCUMENT_PREVIEW_PROCESSING_STALE_SECONDS` | `300` | Marker processing yang lebih tua dianggap stale dan boleh dijadwalkan ulang. |
| `DOCUMENT_PREVIEW_TEMP_RETENTION_SECONDS` | `86400` | Temporary workspace lama dibersihkan best-effort pada conversion berikutnya. |
| `DOCUMENT_PREVIEW_POLL_SECONDS` | `2` | Interval polling UI. |
| `DOCUMENT_PREVIEW_MAX_POLL_ATTEMPTS` | `30` | Batas polling otomatis UI. |
| `DOCUMENT_PREVIEW_MAX_INPUT_BYTES` | `52428800` | Batas input converter, default 50 MB. |
| `DOCUMENT_PREVIEW_MAX_OUTPUT_BYTES` | `104857600` | Batas PDF output, default 100 MB. |
| `DOCUMENT_PREVIEW_MAX_ARCHIVE_ENTRIES` | `5000` | Batas jumlah entry OOXML. |
| `DOCUMENT_PREVIEW_MAX_UNCOMPRESSED_BYTES` | `209715200` | Batas total uncompressed OOXML, default 200 MB. |
| `DOCUMENT_PREVIEW_MAX_COMPRESSION_RATIO` | `100` | Batas rasio kompresi per entry. |
| `DOCUMENT_PREVIEW_MAX_RELATIONSHIP_BYTES` | `5242880` | Batas total data relationship OOXML, default 5 MB. |
| `DOCUMENT_PREVIEW_MAX_CONTENT_TYPES_BYTES` | `1048576` | Batas `[Content_Types].xml`, default 1 MB. |

Aplikasi menolak conversion pada semua environment jika `DOCUMENT_PREVIEW_NETWORK_ISOLATED` bukan `true`. Pemeriksaan ini mencegah salah konfigurasi sederhana, tetapi tidak dapat membuktikan firewall atau sandbox benar-benar bekerja.

## Kebijakan Konversi

- DOCX diekspor memakai filter PDF Writer.
- XLSX diekspor memakai filter PDF Calc dengan `SinglePageSheets=false`, sehingga LibreOffice mempertahankan visibility sheet dan print settings workbook. Hidden sheet tidak sengaja dipaksa tampil.
- Fit-to-one-page-width bergantung pada print settings workbook dan perilaku versi LibreOffice. Aplikasi tidak memaksa seluruh sheet menjadi satu halaman karena itu dapat mengecilkan sheet panjang secara ekstrem.
- Temporary LibreOffice user profile dibuat unik per conversion untuk menghindari state sharing dan lock profile lintas job.
- Nama file yang diberikan ke LibreOffice dibangkitkan server dan tidak berasal dari nama file pengguna.

## Proteksi Input

Sebelum konversi, aplikasi menolak:

- ZIP/OOXML rusak, terlalu besar, terlalu banyak entry, atau compression ratio mencurigakan.
- Path traversal, absolute path, null byte, dan duplicate ZIP entries.
- Macro/VBA, embedded object, OLE object, ActiveX, executable part, external link, relationship type aktif, target URI eksternal, dan relationship yang keluar dari package.
- File dengan checksum yang tidak sama dengan metadata snapshot.
- Dokumen yang tidak termasuk snapshot submission yang sedang dilihat.

Validasi ini mengurangi attack surface tetapi bukan pengganti patch rutin LibreOffice dan sandbox OS/container.

## Validasi Sebelum Aktivasi

Jalankan pada environment staging dengan dokumen non-rahasia:

```bash
php artisan config:clear
php artisan queue:work database --queue=office-preview --sleep=1 --tries=3 --timeout=75
php artisan test tests/Feature/OfficeDocumentPreviewTest.php
```

Verifikasi secara manual:

- DOCX 10+ halaman mempertahankan tabel, header/footer, gambar, page break, landscape section, simbol, dan font.
- XLSX dengan beberapa visible/hidden sheet hanya menampilkan visible sheet, urutannya benar, dan pagination dapat dibaca.
- Original download byte-identical sebelum dan setelah preview.
- Cache tidak dapat diakses langsung melalui URL publik.
- Reviewer tanpa assignment dan dokumen di luar snapshot ditolak.
- Timeout atau LibreOffice crash menghasilkan fallback/retry tanpa mengubah workflow.
- Firewall/sandbox test membuktikan LibreOffice tidak dapat membuka koneksi keluar.
- Memory/CPU limit menghentikan input ekstrem tanpa menjatuhkan web worker.

## Operasional dan Rollback

- Monitor failed jobs, durasi conversion, ukuran cache, disk usage, dan LibreOffice crash/timeout.
- Preview berstatus processing melakukan polling terbatas. Jika worker tidak aktif, UI berhenti polling; marker stale dapat dijadwalkan ulang tanpa infinite loading.
- Status `failed` tidak mengubah keputusan review. Reviewer tetap dapat mengunduh original.
- Untuk rollback, set `DOCUMENT_PREVIEW_ENABLED=false`, reload config, dan hentikan worker queue `office-preview`. Preview PDF/gambar serta original download tetap berjalan.
- Cache adalah artefak turunan. Cache dapat dihapus dan dibuat ulang; jangan menghapus original private file.

Kebijakan retensi cache:

- Cache untuk kombinasi document ID, SHA-256, dan profile version aktif dipertahankan selama record dokumen masih dibutuhkan.
- Cache profile/checksum yang sudah tidak aktif dapat dipurge setelah 30 hari melalui prosedur storage terkontrol. Fase ini belum menyediakan purge command otomatis.
- Temporary workspace berumur lebih dari `DOCUMENT_PREVIEW_TEMP_RETENTION_SECONDS` dibersihkan best-effort saat conversion berikutnya berjalan.
- Status marker ditimpa oleh state terbaru dan tidak boleh diperlakukan sebagai audit record.
- Original private file tidak termasuk cache dan tidak boleh dihapus oleh cleanup preview.

## Batasan

- Konversi nyata tidak dapat dianggap lolos hanya dari automated test karena test memakai fake converter.
- Fidelity dipengaruhi versi LibreOffice, font host, locale, print settings workbook, dan fitur Microsoft Office yang tidak didukung LibreOffice.
- Aplikasi memberlakukan process timeout dan output-size limit, tetapi hard memory/CPU/process limits harus diterapkan oleh OS atau container.
- Hard Laravel job timeout tidak setara di semua platform; Symfony Process timeout adalah batas portable utama, sedangkan process-tree dan resource termination harus diuji pada host Windows/Linux aktual.
- Converter berjalan sebagai child process worker. Network isolation harus dibuktikan pada executable tersebut; flag konfigurasi saja tidak cukup.
