<?php

namespace App\Filament\Pages\Miscellaneous;

use Filament\Pages\Page;

class UserManual extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'User Manual';

    protected static ?string $title = 'User Manual';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament-panels::pages.page';

    public function getHeading(): string
    {
        return 'User Manual';
    }

    public function getSubheading(): ?string
    {
        return implode("\n\n", [
            'Petra LDAP Dashboard adalah aplikasi administrasi LDAP berbasis Filament yang digunakan untuk mengelola koneksi LDAP, user, directory object, schema, operasi import, export, job, queue, audit log, system log, dan health check.',

            '1. Dashboard digunakan untuk melihat ringkasan kondisi sistem, status operasi, observability, dan informasi penting sebelum administrator melakukan perubahan pada LDAP.',

            '2. Directory Management digunakan untuk mengelola LDAP Connections, Users, Directory Object Manager, dan Schema Browser. Bagian ini berfokus pada data LDAP dan struktur directory.',

            '3. LDAP Connections digunakan untuk menyimpan dan menguji koneksi ke server LDAP. Administrator harus memastikan host, port, bind DN, base DN, dan credential sudah benar sebelum menjalankan operasi LDAP.',

            '4. Users digunakan untuk melihat dan mengelola entry user LDAP. Data user mengikuti attribute LDAP seperti uid, cn, sn, mail, DN, objectClass, membership, dan attribute lain yang tersedia.',

            '5. Directory Object Manager digunakan untuk melihat object LDAP secara lebih fleksibel, termasuk OU, CN, user, group, role, application group, dan object lain di dalam base DN.',

            '6. Schema Browser digunakan untuk melihat struktur schema LDAP, objectClass, attribute type, required attribute, optional attribute, dan aturan schema sebelum membuat atau mengubah object LDAP.',

            '7. Operations digunakan untuk menjalankan pekerjaan administratif seperti Operation Jobs, Operation Job Items, LDIF Exports, Import Template Maker, LDAP Transfer Center, Imports CRUD Operations, Import Apply Plans, Command Executions, dan Queue Jobs.',

            '8. Imports CRUD Operations digunakan untuk upload file import, preview data, mengecek konflik, membuat apply plan, dan menjalankan perubahan LDAP secara aman. Gunakan Safe Mode dan Preview Only sebelum apply perubahan.',

            '9. Observability digunakan untuk memantau Audit Logs, Failed Jobs, System Logs, dan Health Checks. Bagian ini membantu administrator mengecek error, aktivitas sistem, dan kondisi layanan.',

            '10. Keycloak digunakan sebagai identity provider untuk login, realm, client, role, group, authentication flow, required action, dan user federation. Menu Keycloak hanya menyediakan akses cepat ke Keycloak Admin Console.',

            '11. Kubernetes digunakan untuk menjalankan layanan seperti dashboard, LDAP, Keycloak, database, ingress, TLS, dan service terkait. Jika terjadi masalah produksi, cek pod, service, ingress, certificate, log, dan namespace.',

            '12. Aturan keamanan utama: selalu cek preview sebelum apply, pastikan target DN benar, jangan menjalankan operasi destructive tanpa backup, cek audit log setelah perubahan, dan gunakan health check untuk memastikan sistem tetap stabil.',
        ]);
    }
}
