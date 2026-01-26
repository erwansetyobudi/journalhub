# JournalHub

JournalHub adalah sistem manajemen dan analisis metadata jurnal ilmiah berbasis **OAI-PMH**. Aplikasi ini mengintegrasikan proses pemanenan data, penyimpanan lokal (cache MySQL), analisis bibliometrik, dan visualisasi jaringan dalam satu platform.

## Lisensi
JournalHub dilisensikan di bawah **GNU GPL versi 3**.  
Lihat berkas **GPL-3.0 License.txt** untuk detail lisensi.

## Fitur Utama
- Harvest metadata jurnal OJS (OAI-PMH)
- Cache metadata ke MySQL (mengurangi hit ke server OAI)
- Dashboard multi-jurnal
- Ekspor data CSV dan JSON
- Statistik bibliometrik (artikel, DOI, tren publikasi)
- Visualisasi jaringan:
  - Co-author network
  - Subject/keyword network
  - Author–subject network
- Klasifikasi jurnal berdasarkan **Rumpun Ilmu** dan **Penerbit**

## Kebutuhan Sistem
- **PHP ≥ 8.1**
- **MySQL 5.7** atau **MariaDB 10.3**
- Web server: Apache / Nginx

### Ekstensi PHP Wajib
- `pdo_mysql`
- `openssl`
- `libxml`
- `simplexml`
- `gettext`
- `mbstring`
- `json`

### Ekstensi PHP Direkomendasikan
- `curl` (disarankan untuk stabilitas OAI-PMH)
- `dom`

## Instalasi Singkat
1. Clone atau salin aplikasi ke direktori web server
2. Buat database dan impor struktur SQL
3. Atur koneksi database di `db.php`
4. Pastikan folder cache dapat ditulis
5. Akses melalui browser

## Penjadwalan Harvest (Opsional)
Gunakan cron (Linux) atau Task Scheduler (Windows) untuk menjalankan `harvest.php` secara berkala.

## Pengembang
**Erwan Setyo Budi**  
GitHub: https://github.com/erwansetyobudi

<img width="1366" height="2509" alt="image" src="https://github.com/user-attachments/assets/7e01a9ee-2170-4b01-861d-a2016d34b867" />
<img width="1366" height="2717" alt="image" src="https://github.com/user-attachments/assets/85e8786f-0bcf-4070-9192-60574fbd96f2" />

<img width="1366" height="1286" alt="image" src="https://github.com/user-attachments/assets/2bb215cf-3806-424f-86aa-fe78a4f16c5a" />


