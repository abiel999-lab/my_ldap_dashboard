<?php

namespace App\Filament\Widgets\UserManual;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserManualDetailWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return match (request()->route('section')) {
            'pengantar' => $this->pengantar(),
            'dashboard' => $this->dashboard(),
            'directory' => $this->directory(),
            'connections' => $this->connections(),
            'operations' => $this->operations(),
            'bulk' => $this->bulk(),
            'impor-ekspor' => $this->imporExport(),
            'observability' => $this->observability(),
            'concepts' => $this->concepts(),
            'troubleshooting' => $this->troubleshooting(),
            'sop' => $this->sop(),
            default => $this->pengantar(),
        };
    }

    private function card(string $label, string $title, string $description, string $icon, string $color = 'primary'): Stat
    {
        return Stat::make($label, $title)
            ->description($description)
            ->descriptionIcon($icon)
            ->color($color);
    }

    private function pengantar(): array
    {
        return [
            $this->card('Tujuan Aplikasi', 'Administrasi LDAP Terpusat', 'Aplikasi ini membantu administrator mengelola koneksi LDAP, pengguna, grup, peran, OU, schema, impor, ekspor, transfer, sinkronisasi, antrean, audit log, system log, health check, dan Keycloak dari satu dashboard.', 'heroicon-o-information-circle', 'info'),
            $this->card('Cara Kerja', 'Pengelolaan Data LDAP', 'Aplikasi tidak hanya membaca data LDAP, tetapi juga dapat menjalankan operasi perubahan seperti add atribut, repindahkan atribut, add objectClass, pindahkan DN, impor, transfer, dan bulk operation.', 'heroicon-o-cog-6-tooth', 'warning'),
            $this->card('Prinsip Aman', 'Pratinjau Sebelum Penerapan', 'Setiap operasi massal harus dimulai dari pratinjau. Jangan terapkan jika jumlah target, DN, filter, atau rencana perubahan belum sesuai dengan ekspektasi.', 'heroicon-o-shield-check', 'success'),
            $this->card('Log Aktivitas', 'Pencatatan Aktivitas Sistem', 'Gunakan Audit Logs, Operation Jobs, Command Executions, Failed Jobs, dan System Logs untuk melacak siapa melakukan apa, kapan dilakukan, dan apakah berhasil atau gagal.', 'heroicon-o-document-magnifying-glass', 'gray'),
            $this->card('Production Safety', 'Hindari Percobaan Langsung pada Produksi', 'Untuk koneksi production, hindari percobaan destructive. Test dulu pada LDAP testing atau pada 1 sampai 3 entri sebelum operasi besar.', 'heroicon-o-exclamation-triangle', 'danger'),
            $this->card('Verifikasi', 'Verifikasi pada Direktori LDAP', 'Setelah terapkan, verifikasi hasil di Apache Directory Studio, ldapsearch, Operation Jobs, Audit Logs, dan refresh cache aplikasi bila diperlukan.', 'heroicon-o-check-circle', 'success'),
        ];
    }

    private function dashboard(): array
    {
        return [
            $this->card('Directory Summary', 'Kondisi Direktori', 'Menampilkan ringkasan LDAP Connections, Users, Groups, Applications, Units / OU, Schema Entries, atau Directory Explorer sesuai widget yang aktif di dashboard.', 'heroicon-o-folder', 'primary'),
            $this->card('Operations Summary', 'Status Pekerjaan Sistem', 'Menampilkan antreand jobs, running jobs, gagal operations, dan gagal antrean jobs. Bagian ini harus dicek sebelum menjalankan operasi LDAP besar.', 'heroicon-o-antrean-list', 'warning'),
            $this->card('Security Summary', 'Aktivitas Administrator', 'Menampilkan audit logs, command executions, impor batches, dan LDIF ekspors. Gunakan untuk melihat aktivitas terbaru di sistem.', 'heroicon-o-shield-check', 'success'),
            $this->card('Recent Jobs', 'Riwayat Operasi Terbaru', 'Tabel recent jobs membantu melihat operasi paling baru, status, total item, jumlah OK, jumlah gagal, dan waktu operasi dibuat.', 'heroicon-o-clock', 'gray'),
            $this->card('Waktu Pemeriksaan', 'Sebelum Operasi', 'Cek dashboard sebelum impor, transfer, sinkronisasi, bulk operation, hapus entri, pindahkan DN, atau operasi lain yang mengubah LDAP.', 'heroicon-o-eye', 'info'),
            $this->card('Indikasi Masalah', 'Failed Naik', 'Jika gagal operations atau gagal antrean jobs bertambah, jangan lanjutkan operasi baru sebelum penyebabnya dicek di Failed Jobs dan System Logs.', 'heroicon-o-exclamation-circle', 'danger'),
        ];
    }

    private function directory(): array
    {
        return [
            $this->card('LDAP Servers', 'Informasi Server LDAP', 'Mencatat identitas server LDAP seperti production, staging, testing, atau external LDAP. Ini membantu dokumentasi infrastruktur.', 'heroicon-o-server', 'gray'),
            $this->card('Users', 'Data Pengguna LDAP', 'Melihat DN, uid, cn, sn, mail, displayName, objectClass, raw atributs, source connection, dan last sinkronisasi pengguna.', 'heroicon-o-penggunas', 'info'),
            $this->card('Groups', 'Keanggotaan LDAP', 'Melihat grup DN, cn, member, objectClass, status, dan relasi grup untuk peran, application access, atau security policy.', 'heroicon-o-pengguna-grup', 'success'),
            $this->card('Units / OU', 'Unit Organisasi', 'Melihat OU, parent DN, child count, status, dan target yang biasa dipakai untuk pindahkan DN atau filter operasi.', 'heroicon-o-building-office', 'warning'),
            $this->card('Object Manager', 'Pengelolaan Objek LDAP', 'Mengelola object LDAP umum seperti OU, CN, UID, grup, peran, application grup, service account, device, dan object lain.', 'heroicon-o-folder', 'primary'),
            $this->card('Schema Browser', 'Skema dan Aturan LDAP', 'Melihat objectClass, atributType, MUST, MAY, syntax, single value, multi value, structural, dan auxiliary schema.', 'heroicon-o-book-open', 'gray'),
        ];
    }

    private function connections(): array
    {
        return [
            $this->card('Host & Port', 'Alamat Server LDAP', 'Host adalah alamat server LDAP. Port umum adalah 389 untuk LDAP biasa dan 636 untuk LDAPS.', 'heroicon-o-wifi', 'primary'),
            $this->card('Base DN', 'Dasar Pencarian LDAP', 'Base DN adalah titik awal pencarian LDAP, misalnya dc=petra,dc=ac,dc=id atau dc=petra,dc=dev.', 'heroicon-o-map', 'info'),
            $this->card('Bind DN', 'Akun Autentikasi LDAP', 'Bind DN adalah akun yang dipakai aplikasi untuk login ke LDAP, misalnya cn=admin,dc=petra,dc=dev.', 'heroicon-o-key', 'warning'),
            $this->card('SSL / TLS', 'Keamanan Koneksi', 'Use SSL dipakai untuk LDAPS. Use TLS dipakai untuk StartTLS. Jangan aktifkan sembarangan jika server tidak mendukung.', 'heroicon-o-lock-closed', 'success'),
            $this->card('Mapping Attribute', 'Pemetaan Pengguna dan Grup', 'Mapping menentukan atribut pengguna identifier, display name, email, grup member, UUID, pengguna base DN, dan grup base DN.', 'heroicon-o-adjustments-horizontal', 'gray'),
            $this->card('Read-only Mode', 'Penanda Keamanan', 'Read-only mode menandai koneksi agar tidak dipakai untuk operasi destructive. Cocok untuk koneksi yang hanya boleh dibaca.', 'heroicon-o-eye', 'danger'),
        ];
    }

    private function operations(): array
    {
        return [
            $this->card('LDIF Export', 'Pencadangan Data', 'Export LDAP ke LDIF berdasarkan connection, base DN, search scope, filter, atributs, dan size limit.', 'heroicon-o-arrow-down-tray', 'info'),
            $this->card('Import Center', 'Impor Bertahap', 'Import harus melalui template, batch, row validation, generate plan, conflict check, lalu terapkan jika aman.', 'heroicon-o-arrow-up-tray', 'success'),
            $this->card('Transfer Center', 'Transfer Antar LDAP', 'Transfer digunakan untuk copy data dari source LDAP ke target LDAP dengan filter, target parent DN, dan collision strategy.', 'heroicon-o-arrows-right-left', 'warning'),
            $this->card('Sync Center', 'Pembaruan Cache', 'Sync membaca LDAP asli dan memperbarui cache aplikasi. Jalankan setelah perubahan manual, impor, transfer, atau bulk operation.', 'heroicon-o-arrow-path', 'primary'),
            $this->card('Operation Jobs', 'Pelacakan Operasi', 'Melihat status antreand, running, success, gagal, partial gagal, skipped, target DN, item logs, dan metadata operasi.', 'heroicon-o-antrean-list', 'gray'),
            $this->card('Command Executions', 'Log Teknis', 'Mencatat command administratif, stdout, stderr, pratinjau command, target DN, error message, dan execution result.', 'heroicon-o-command-line', 'danger'),
        ];
    }

    private function bulk(): array
    {
        return [
            $this->card('Target Mode', 'Penentuan Target Operasi', 'Base DN + Filter untuk banyak entri. Custom Target DN untuk satu target spesifik. RDN Attribute + Value untuk target berbasis uid, cn, atau ou.', 'heroicon-o-map-pin', 'primary'),
            $this->card('Search Scope', 'Cakupan Pencarian', 'Base hanya membaca entri itu sendiri. One Level membaca child satu level. Full Subtree membaca semua child di bawah base DN.', 'heroicon-o-magnifying-glass', 'info'),
            $this->card('Add ObjectClass', 'Penambahan ObjectClass Auxiliary', 'Menambahkan auxiliary objectClass ke banyak entri. Jika objectClass punya MUST atribut, isi value wajib sebelum terapkan.', 'heroicon-o-plus-circle', 'success'),
            $this->card('Delete ObjectClass', 'Penghapusan ObjectClass', 'Menghapus objectClass bisa membuat atribut terkait tidak valid. Pastikan pratinjau benar dan rollback payload tersedia.', 'heroicon-o-minus-circle', 'danger'),
            $this->card('Move To OU', 'Pemindahan Parent DN', 'Memindahkan entri ke parent DN baru. Pastikan target parent DN sudah ada dan jangan memindahkan OU besar tanpa memahami child object.', 'heroicon-o-arrow-right-circle', 'warning'),
            $this->card('Delete Entry', 'Operasi Destruktif', 'Delete entri harus memakai filter spesifik. Test pada 1 sampai 3 entri dulu sebelum operasi besar.', 'heroicon-o-trash', 'danger'),
        ];
    }

    private function imporExport(): array
    {
        return [
            $this->card('LDIF Export', 'Pencadangan Sebelum Operasi', 'Gunakan ekspor sebelum operasi besar agar ada backup data LDAP dalam bentuk LDIF.', 'heroicon-o-arrow-down-tray', 'info'),
            $this->card('Import Template', 'Format Data', 'Template impor membantu menyamakan field, DN, objectClass, atribut wajib, dan mapping data sebelum impor.', 'heroicon-o-document-text', 'gray'),
            $this->card('Import Batch', 'Kumpulan Data Impor', 'Batch menyimpan data impor dan status row. Jangan terapkan sebelum validation dan plan aman.', 'heroicon-o-table-cells', 'success'),
            $this->card('Apply Plan', 'Rencana Eksekusi', 'Apply plan menunjukkan perubahan yang akan dibuat, conflict, skipped row, dan target DN.', 'heroicon-o-clipboard-document-check', 'warning'),
            $this->card('Transfer', 'Sumber ke Tujuan', 'Exclude operational atributs seperti entriUUID, entriCSN, createTimestamp, creatorsName, modifyTimestamp, dan modifiersName.', 'heroicon-o-arrows-right-left', 'danger'),
            $this->card('Sync', 'Sinkronisasi Cache', 'Setelah impor atau transfer, jalankan sinkronisasi agar tabel aplikasi sama dengan LDAP asli.', 'heroicon-o-arrow-path', 'primary'),
        ];
    }

    private function observability(): array
    {
        return [
            $this->card('Audit Logs', 'Identitas dan Aktivitas Pelaksana', 'Mencatat actor, module, action, status, target type, target DN, before value, after value, IP, dan waktu kejadian.', 'heroicon-o-document-magnifying-glass', 'success'),
            $this->card('Failed Jobs', 'Pekerjaan Antrean Gagal', 'Melihat job gagal, connection, antrean, payload, exception, dan waktu gagal. Jangan retry destructive job tanpa memahami dampaknya.', 'heroicon-o-x-circle', 'danger'),
            $this->card('System Logs', 'Kesalahan Aplikasi', 'Melihat error Laravel, database, LDAP, Filament, Livewire, permission, dan runtime exception.', 'heroicon-o-bug-ant', 'warning'),
            $this->card('Health Checks', 'Kesehatan Sistem', 'Memantau database, LDAP connection, antrean worker, storage, log permission, dan service penting lain.', 'heroicon-o-heart', 'info'),
            $this->card('Operation Jobs', 'Progres Operasi', 'Melihat total item, OK, gagal, skipped, status, module, action, dan log operasi per item.', 'heroicon-o-antrean-list', 'gray'),
            $this->card('Command Logs', 'Keluaran Teknis', 'Melihat command, stdout, stderr, error message, execution result, dan metadata teknis.', 'heroicon-o-command-line', 'primary'),
        ];
    }

    private function concepts(): array
    {
        return [
            $this->card('Base DN', 'Titik Awal Pencarian', 'Contoh: ou=alumni,ou=people,dc=petra,dc=ac,dc=id. Semua pencarian dimulai dari base DN.', 'heroicon-o-map', 'gray'),
            $this->card('DN', 'Alamat Lengkap Entri', 'Contoh: uid=usr000046,ou=alumni,ou=people,dc=petra,dc=ac,dc=id.', 'heroicon-o-identification', 'gray'),
            $this->card('RDN', 'Bagian Pertama DN', 'Contoh: uid=usr000046. RDN Attribute adalah uid. RDN Value adalah usr000046.', 'heroicon-o-tag', 'gray'),
            $this->card('LDAP Filter', 'Kondisi Pencarian', 'Contoh: (objectClass=*), (uid=usr000046), (mail=*), atau (&(objectClass=inetOrgPerson)(uid=usr000046)).', 'heroicon-o-code-bracket', 'primary'),
            $this->card('Structural ObjectClass', 'Jenis Utama Entri', 'Structural objectClass menentukan jenis utama entri. Umumnya tidak boleh sembarangan diganti atau dibuat dobel.', 'heroicon-o-cube', 'warning'),
            $this->card('Auxiliary ObjectClass', 'Penambahan Atribut', 'Auxiliary objectClass memperluas atribut entri. Contoh domainRelatedObject membutuhkan associatedDomain.', 'heroicon-o-puzzle-piece', 'success'),
        ];
    }

    private function troubleshooting(): array
    {
        return [
            $this->card('LDAP Connection Gagal', 'Periksa Konfigurasi', 'Cek host, port, firewall, bind DN, password, SSL/TLS, Base DN, network, dan schema test.', 'heroicon-o-wifi', 'danger'),
            $this->card('Preview Kosong', 'Filter atau Cakupan Tidak Sesuai', 'Cek Base DN, LDAP Filter, Search Scope, Size Limit, dan coba filter aman (objectClass=*).', 'heroicon-o-magnifying-glass-circle', 'warning'),
            $this->card('Apply Tidak Terlihat', 'Perbarui Tampilan LDAP', 'Refresh Apache Directory Studio, pastikan LDAP Connection benar, cek Operation Jobs, Audit Logs, dan Execution Result.', 'heroicon-o-arrow-path', 'info'),
            $this->card('ObjectClass Gagal', 'Kesalahan Skema', 'Pastikan objectClass auxiliary, MUST atribut lengkap, atribut sesuai schema, dan bukan structural conflict.', 'heroicon-o-exclamation-circle', 'danger'),
            $this->card('Queue Tidak Jalan', 'Masalah Worker', 'Cek Queue Jobs, Failed Jobs, worker Laravel, antrean restart, timeout, dan gagal exception.', 'heroicon-o-clock', 'warning'),
            $this->card('Database Error', 'Migrasi atau Kolom', 'Cek System Logs, migration, model cast, column type, uuid, JSON payload, dan data terlalu panjang.', 'heroicon-o-circle-stack', 'danger'),
        ];
    }

    private function sop(): array
    {
        return [
            $this->card('1', 'Masuk dan Periksa Dashboard', 'Masuk ke aplikasi, buka Dashboard, cek summary, gagal operations, gagal antrean jobs, dan recent jobs.', 'heroicon-o-home', 'primary'),
            $this->card('2', 'Periksa Kesehatan Sistem dan Log', 'Buka Health Checks, Failed Jobs, Audit Logs, dan System Logs sebelum melakukan operasi besar.', 'heroicon-o-heart', 'success'),
            $this->card('3', 'Validasi Koneksi', 'Pastikan LDAP Connection, Base DN, Bind DN, environment, dan read-only mode sudah benar.', 'heroicon-o-link', 'info'),
            $this->card('4', 'Buat Pratinjau', 'Buat operasi, isi target, filter, scope, size limit, lalu generate pratinjau. Jangan lanjut jika target tidak sesuai.', 'heroicon-o-eye', 'warning'),
            $this->card('5', 'Terapkan dan Verifikasi', 'Apply hanya setelah pratinjau benar. Verifikasi melalui Apache Directory Studio, ldapsearch, Operation Jobs, dan Audit Logs.', 'heroicon-o-check-circle', 'success'),
            $this->card('6', 'Dokumentasikan Hasil', 'Catat operasi, waktu, target DN, jumlah sukses, jumlah gagal, dan langkah rollback jika ada.', 'heroicon-o-clipboard-document-check', 'gray'),
        ];
    }
}
