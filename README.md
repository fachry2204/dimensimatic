# Dimensimatic

Aplikasi desktop Windows untuk otomatisasi rilisan Dimensi Suara dan SoundOn, pemeriksaan status, serta pembaruan melalui GitHub Releases.

Repositori: https://github.com/fachry2204/dimensimatic

## Pengembangan

1. Pasang PHP 8.3+, Composer, Node.js, serta .NET 8 SDK untuk launcher Windows.
2. Jalankan `composer install`, `npm ci`, dan `npm --prefix automation-worker ci`.
3. Salin `.env.example` menjadi `.env`, jalankan `php artisan key:generate`, lalu isi konfigurasi lokal. Salin juga `automation-worker/.env.example` menjadi `.env` di folder worker; gunakan HMAC yang sama pada aplikasi dan worker.
4. Jalankan `php artisan migrate`, `npm run build`, dan `npm --prefix automation-worker run build`.
5. Jalankan pengujian dengan `php artisan test` dan skrip test worker dalam `automation-worker/package.json`.

## Pembaruan aplikasi

Tombol Cek Update membaca release terbaru dari `fachry2204/dimensimatic`. Nilai bawaan ini berlaku juga ketika variabel repository kosong. Installer berikutnya mengarahkan konfigurasi instalasi lama ke repository ini tanpa mengganti kunci enkripsi atau data login.

Untuk versi berikutnya, perbarui versi aplikasi, worker, launcher, dan installer; jalankan pengujian; buat installer hanya setelah diminta; lalu terbitkan GitHub Release bertag `vX.Y.Z` dengan aset `Dimensimatic-Setup-vX.Y.Z.exe`. File EXE dibagikan melalui Releases, bukan disimpan dalam riwayat Git.

Repository menyimpan seluruh sumber aplikasi, migrasi, tes, aset ikon, konfigurasi contoh, dan skrip build. Dependensi dapat dipasang ulang dari lockfile. Database pengguna, cookie, sesi browser, file `.env`, log, hasil build, dan kunci rahasia tetap lokal. Build installer membutuhkan 7-Zip, runtime PHP/Node, dan ekstraksi FFmpeg dalam `scripts/installer/ffmpeg-extract`, sesuai `scripts/build-installer.ps1`.

### Link login CMS Dimensi Suara

Buka **Pengaturan Platform → Link Login CMS** untuk mengubah alamat login CMS. Default: `https://dimensisuara.id/login`. Pengaturan disimpan di database dan langsung dikirim ke browser worker tanpa mengubah kode atau restart worker. Domain dari URL login digunakan untuk halaman `/admin/releases`, validasi domain rilisan, dan unduhan aset CMS. Setelah mengganti alamat, login kembali ke Dimensi Suara. Jalankan `php artisan migrate` saat memperbarui aplikasi.

### Instalasi Dimensimatic terpisah

Installer `Dimensimatic-Setup-v1.1.106.exe` memasang aplikasi di `%LOCALAPPDATA%\Programs\Dimensimatic`. EXE, shortcut, database, profil browser, cookie sesi, mutex aplikasi, dan log berada pada identitas Dimensimatic. Service lokal menggunakan port 8012 (aplikasi) dan 3112 (worker). Installer tidak memperbarui atau mengambil data dari instalasi SoundMatic.

Updater hanya menerima paket `Dimensimatic-Setup-vX.Y.Z.exe`. Repository update bawaan adalah `fachry2204/dimensimatic`.

### Nama sistem dan favicon

Pada **Pengaturan Platform → Identitas Sistem**, simpan nama sistem (maksimal 80 karakter) dan unggah favicon PNG/ICO (maksimal 512 KB; PNG hingga 1024 × 1024 piksel). Perubahan berlaku pada halaman login, judul halaman, navigasi, label CMS, pesan operasional, serta judul dan ikon jendela desktop. Nama dan favicon disimpan dalam database. Identitas EXE, installer, folder instalasi, port dan updater tetap Dimensimatic agar instalasi tetap terpisah. Metadata distribusi seperti publisher/record label tetap memakai identitas katalog.

### Verifikasi CMS setelah status SoundOn

Cek Status Rilis otomatis mengantrekan Verify Release untuk status SoundOn Approved, Delivered (delivery), atau Live jika UPC dan seluruh ISRC valid. Worker membuka modal Publish Release di CMS, mengisi UPC dan ISRC sesuai nomor track, lalu menekan Verify Release. Status Verified/Published dan kode yang tersimpan harus tetap terbaca setelah reload sebelum aplikasi menandai berhasil. Under Review dan Not Approved tidak memicu verifikasi.


### Publish Rilis (v1.1.100)

Daftar Publish Rilis berasal dari seluruh rilisan Under Review CMS, bukan koleksi seluruh draft SoundOn. Klik Ambil Semua Under Review CMS untuk membersihkan tabel lama dan membaca CMS di latar belakang. Progres menampilkan jumlah terbaca, total bila tersedia, dan halaman; hasil baru tampil setelah pengambilan lengkap. Pengambilan dapat dipantau kembali setelah halaman dibuka ulang.

Pilih satu atau semua rilisan. Setiap pekerjaan mencari judul yang sama di draft SoundOn melalui pencarian library. Satu hasil judul unik langsung dibuka; bila judul berulang, nama artis digunakan sebagai pembeda. Nama yang dipotong dibaca dari atribut, tooltip atau diverifikasi di editor sebelum Next/Submit. Kolom pencarian tanpa placeholder didukung. Pencarian mendukung daftar React tanpa tbody dan menolak pasangan judul/artis ganda yang masih ambigu. Konfirmasi sesudah Submit juga menggunakan ID draft yang telah dibuka agar teks artis yang dipotong tidak menggagalkan konfirmasi. Pasangan judul/artis ganda pada CMS diblokir sebelum masuk antrean.

Submit SoundOn harus terkonfirmasi melalui status rilisan sebelum tombol Upload Release CMS dijalankan. Status Upload DSP/Uploading diperiksa lagi setelah reload. Penanda publish SoundOn disimpan; jika hanya CMS gagal, retry melanjutkan CMS tanpa submit ulang. Penanda lama tetap digunakan saat beralih ke daftar berbasis CMS. Status SoundOn yang sudah berhasil dapat dipulihkan dari pasangan unik di library ketika respons sebelumnya terputus.

Alasan gagal disimpan per rilisan, termasuk draft tidak ditemukan, pasangan ambigu, masalah sesi, tahap Next/Submit, pesan validasi, label kolom invalid, dan tombol nonaktif. Pekerjaan berjalan pada antrean cms-verification. Alur pembuatan draft otomatis tetap menyimpan draft; submit hanya dijalankan melalui pilihan operator di Publish Rilis.


Perbaikan v1.1.100 menunggu halaman library/Draft siap sebelum pencarian dan memakai ketikan keyboard untuk input React/onKeyUp. Judul dibandingkan dari innerText yang terlihat, mengabaikan anak tooltip tersembunyi dan karakter zero-width. Pencarian tetap mengutamakan judul; artis hanya membedakan judul ganda. Redirect login dideteksi sebelum pencarian maupun konfirmasi status; job mencoba memperbarui sesi SoundOn satu kali. Diagnostik draft tidak ditemukan mencantumkan URL halaman, query aktual, dan sampel baris yang terlihat tanpa isian login.


Progress per rilisan v1.1.100 disimpan pada publish_releases dan tetap tampil setelah halaman dibuka ulang. Antrean mulai 1%, persiapan sesi 5%, tahap browser 10–85% (mencari draft, membuka editor, Next, Submit, konfirmasi SoundOn), pembaruan CMS 90%, dan selesai 100% hanya setelah status CMS terkonfirmasi. Persentase menunjukkan tonggak tahap proses, bukan perkiraan durasi. Snapshot browser diambil lewat endpoint bertanda tangan tanpa memakai slot browser, sehingga tetap tersedia selama publish berlangsung. ID percobaan dan penjagaan status mencegah respons polling lama menimpa percobaan baru atau hasil sukses. Bar gagal berwarna merah dan mempertahankan progres terakhir. Retry yang SoundOn-nya sudah berhasil melanjutkan dari checkpoint 85% ke CMS.


v1.1.100 menunggu perubahan heading/step/form setelah tiap Next, hingga tiga tahap sebelum Submit. Editor yang sudah berada di Review dapat langsung Submit. Permintaan Submit tidak ditinggalkan setelah jeda 700 ms: aplikasi menunggu request selesai, pesan penerimaan SoundOn atau dialog konfirmasi yang muncul terlambat. Penolakan HTTP dan kode kegagalan pada respons JSON ditampilkan pada error rilisan tanpa menyalin payload request. Jika pesan penerimaan belum tersedia, pemeriksaan status dilakukan di tab browser baru dengan All releases dipilih secara eksplisit; editor asal dipertahankan. UI penerimaan yang terkonfirmasi atau status positif rilisan menjadi syarat sebelum Upload Release CMS.

Pemeriksaan setelah Submit membaca status yang sudah dirender tanpa mengisi ulang pencarian atau membatalkan request library pada tiap putaran; refresh dilakukan setelah beberapa kali pembacaan. Progres Submit dan pemeriksaan menyertakan jumlah Next yang telah selesai.

### Cek status rilis (v1.1.101)

Pemeriksaan memilih All releases dan menghapus query sebelumnya sebelum mencari rilisan. Input pencarian memakai event keyboard agar handler SoundOn terpicu. Hasil debounce dan indikator loading ditunggu sebelum pembacaan, termasuk pergantian halaman yang lambat. Timeout loading atau pagination dilaporkan sebagai kegagalan pemeriksaan, bukan rilisan tidak ditemukan. Uji browser mencakup pencarian beberapa judul berurutan dengan hasil tertunda dan pagination tertunda; belum divalidasi pada akun SoundOn nyata.

### Pemakaian ulang sesi platform

Cookie, localStorage, IndexedDB, dan sessionStorage dipulihkan untuk aktivitas berikutnya. Worker menyimpan pembaruan sesi di `automation-worker/storage/session-cache` dengan enkripsi AES-GCM memakai kunci yang diturunkan dari AUTOMATION_HMAC_KEY. Snapshot sesi pada database tetap terenkripsi. Cache yang tidak dapat dibaca kembali memakai snapshot database; cache tidak disertakan pada installer.

Validasi membuka halaman yang memerlukan autentikasi, bukan halaman login. Error jaringan, halaman error, dan CAPTCHA saat validasi tidak menghapus sesi tersimpan. Hasil validasi yang berhasil menyimpan snapshot terbaru ke database. Pemeriksaan/login diserialkan per platform untuk menghindari login bersamaan. Menyimpan credential yang sama mempertahankan sesi; mengganti credential atau link CMS membatalkan sesi terkait. Kegagalan autentikasi saat proses rilisan hanya meminta validasi ulang platform yang bersangkutan. Perubahan worker berlaku setelah proses worker dimulai ulang; sesi tetap bergantung pada masa berlaku yang ditentukan platform.

### Pemulihan pencarian draft dan antrean publish

Jika pencarian judul tidak menemukan draft, publish menghapus query lalu memeriksa halaman daftar Draft. Label judul lengkap pada atribut aksesibilitas dapat dipakai saat teks tampilan terpotong. Kegagalan loading tetap dilaporkan sebagai loading, bukan rilisan hilang. Uji browser mencakup pencarian kosong, pemulihan dari halaman kedua, tiga kali Next, dan tepat satu Submit.

Reservasi antrean database minimal 3600 detik, lebih panjang daripada timeout job publish 2700 detik. Ini mencegah job yang masih berjalan diambil worker lain setelah batas bawaan 90 detik. Kunci per rilisan membuang pesan antrean duplikat selama proses yang sama berlangsung. Perubahan konfigurasi dan kode memerlukan pemuatan ulang konfigurasi serta restart worker. Pengujian memakai halaman simulasi dan tidak mengirim lagu ke akun SoundOn nyata.

### Pemeriksaan status berdasarkan tampilan SoundOn saat ini

All releases dan dropdown All status adalah dua kontrol berbeda; pemeriksaan meniadakan pembatas status sebelum mencari. SoundOn memecah judul lengkap menjadi banyak kecocokan, sehingga pencarian mengutamakan kata khas judul lalu mencocokkan judul lengkap serta artis pada hasil. Setiap query kembali ke halaman pertama. Permintaan /api/search/entity dan row key hasilnya ditunggu agar tabel lama tidak dibaca sebagai hasil baru. Pagination mendukung li role=button, ikon Semi UI, dan nomor halaman; penghitung Showing dipakai untuk menolak hasil tidak lengkap. Jika pencarian gagal menemukan rilisan, daftar tanpa filter dipindai sekali untuk semua rilisan yang belum cocok. Detail Live/Approved/Delivered tidak menunggu pesan penolakan.

Pemeriksaan browser langsung pada 10 November 2026 menemukan The Forever Blooming Rose, A Paradise of Roses, dan The Roots of True Love dalam status Delivered. Bukti lokal disimpan di storage/soundon-blooming-search.png, storage/soundon-paradise-search.png, dan storage/soundon-roots-search.png. Perubahan kode memerlukan restart worker; installer tidak dibangun otomatis.

### Tampilan pemeriksaan status terbaru

Saat cek penuh dimulai, filter pencarian/status/tanggal direset dan tabel serta kartu ringkasan hanya mengambil snapshot pemeriksaan yang dipilih. Tampilan lama disembunyikan selama permintaan mulai berjalan. ID snapshot disimpan per pengguna agar pembaruan halaman tetap menampilkan pemeriksaan yang sama. Tombol dan indikator menampilkan tahap menunggu antrean, mengambil daftar Uploading, dan memeriksa X/Y rilisan; tombol kembali aktif pada hasil selesai, gagal, atau login manual. Status lama yang masih menunggu pemeriksaan ulang tidak ditampilkan sebagai hasil baru.

Dispatcher mencatat tahap dan identitas pesan antreannya, serta memiliki failed callback untuk menghentikan status berjalan saat worker gagal. Polling memulihkan run yang tidak lagi mempunyai pesan antrean sendiri setelah jeda 15 detik; pesan reserved tetap dianggap aktif. Pekerjaan milik run lain tidak menahan tombol pemeriksaan yang sudah selesai. Pesan antrean lama tidak dapat menghidupkan ulang snapshot yang sudah dihapus. Riwayat pipeline di luar snapshot tidak dihapus untuk mengosongkan tampilan.

### Penolakan otomatis dari SoundOn

Saat Cek Status Rilis menemukan Not Approved / Rejected, aplikasi mengantrekan Reject Release di Dimensi Suara dan mengisi alasan penolakan asli SoundOn, termasuk beberapa baris alasan. Pengiriman CMS memakai antrean tersendiri sehingga pemeriksaan SoundOn tetap berjalan. Status lokal berubah menjadi Rejected setelah browser memastikan status CMS tersimpan setelah reload. Jika alasan tidak terbaca atau pengiriman gagal, kesalahan tampil pada tabel; operator dapat memeriksa ulang atau mengisi alasan melalui tombol Reject. Antrean berulang dan pekerjaan yang telah dihentikan tidak dikirim ulang otomatis oleh polling.

### Identitas label dan Whitelabel

Metadata rilisan mencatat `whitelabel_active` dan `label_name`. Jika status aktif, Record label, P-line, dan C-line SoundOn diisi dengan Nama Label yang sama; nama label kosong harus diperbaiki sebelum membuat draft. Jika tidak aktif, seluruhnya memakai Dimensi Suara. Tahun P-line/C-line mengikuti SoundOn. Pembacaan detail CMS mendukung baris Nama Label / Record Label dan Status Whitelabel / Whitelabel dengan nilai Aktif atau Tidak Aktif. Tanpa penanda aktivasi, nama label saja tidak mengaktifkan Whitelabel. Status ditampilkan di bawah Label pada metadata detail run.
