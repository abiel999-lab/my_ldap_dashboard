<?php

namespace App\Filament\Pages\Miscellaneous;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use UnitEnum;

class UserManual extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

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
        return 'Pilih salah satu tombol manual di kanan atas untuk membaca dokumentasi.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->manualAction(
                'pengantar',
                '1. Pengantar',
                'heroicon-o-information-circle',
                'info',
                '1. Pengantar Aplikasi',
                [
                    'Petra LDAP Dashboard adalah aplikasi administrasi LDAP berbasis Laravel dan Filament.',
                    'Aplikasi ini digunakan untuk membantu administrator mengelola LDAP Connection, user, group, role, unit, schema, import, export, transfer, sync, queue, audit log, system log, health check, dan integrasi Keycloak.',
                    'Aplikasi ini memudahkan administrator bekerja tanpa selalu memakai command line seperti ldapsearch, ldapmodify, ldapadd, atau ldapdelete.',
                ],
                [
                    'Selalu pastikan LDAP Connection benar.',
                    'Selalu baca Base DN, Target DN, LDAP Filter, dan Search Scope.',
                    'Untuk operasi massal, selalu jalankan Preview terlebih dahulu.',
                    'Jangan Apply jika hasil Preview belum sesuai.',
                    'Gunakan Rollback jika tersedia.',
                    'Gunakan Audit Logs, Operation Jobs, Queue Jobs, Failed Jobs, dan System Logs untuk tracking.',
                ]
            ),

            $this->manualAction(
                'dashboard',
                '2. Dashboard',
                'heroicon-o-squares-2x2',
                'gray',
                '2. Dashboard',
                [
                    'Dashboard adalah halaman awal setelah administrator masuk ke aplikasi.',
                    'Dashboard digunakan untuk melihat ringkasan kondisi sistem sebelum menjalankan operasi LDAP.',
                ],
                [
                    'Directory Foundation Coverage menampilkan ringkasan Applications, Units / OU, Schema Entries, dan Directory Explorer.',
                    'Operations Summary menampilkan Queued Jobs, Running Jobs, Failed Operations, dan Failed Queue Jobs.',
                    'Security / Activity Summary menampilkan Audit Logs, Command Executions, Import Batches, dan LDIF Exports.',
                    'Recent Operation Jobs menampilkan operasi terbaru yang berjalan di sistem.',
                ]
            ),

            $this->manualAction(
                'directory',
                '3. Directory',
                'heroicon-o-folder',
                'primary',
                '3. Directory Management',
                [
                    'Directory Management digunakan untuk mengelola dan membaca struktur LDAP.',
                ],
                [
                    'LDAP Servers: mencatat informasi server LDAP.',
                    'LDAP Connections: menyimpan host, port, base DN, bind DN, password, SSL/TLS, mapping user, dan mapping group.',
                    'Users: melihat dan mengelola user LDAP.',
                    'Directory Object Manager: mengelola object LDAP umum seperti OU, CN, UID, group, role, service account, dan object lain.',
                    'Directory Explorer: membaca struktur LDAP generic dari base DN.',
                    'Schema Browser: melihat objectClass, attributeType, MUST, MAY, syntax, structural, dan auxiliary schema.',
                ]
            ),

            $this->manualAction(
                'connections',
                '4. Connections',
                'heroicon-o-link',
                'primary',
                '4. LDAP Connections',
                [
                    'LDAP Connections adalah menu paling penting karena menyimpan konfigurasi koneksi ke LDAP server.',
                ],
                [
                    'Connection Name: nama koneksi LDAP.',
                    'Environment: production, staging, testing, local.',
                    'Host: alamat LDAP server.',
                    'Port: 389 untuk LDAP biasa, 636 untuk LDAPS.',
                    'Base DN: root pencarian LDAP.',
                    'Bind DN: akun LDAP untuk login.',
                    'Bind Password: password bind.',
                    'Use SSL / TLS: pengaturan keamanan koneksi.',
                    'Active: menentukan koneksi bisa dipakai.',
                    'Default Connection: menentukan koneksi utama.',
                    'Read-only Mode: menandai koneksi agar tidak dipakai untuk operasi destructive.',
                ]
            ),

            $this->manualAction(
                'operations',
                '5. Operations',
                'heroicon-o-command-line',
                'warning',
                '5. Operations',
                [
                    'Operations digunakan untuk pekerjaan administratif dan pekerjaan massal.',
                ],
                [
                    'LDIF Exports: export data LDAP menjadi file LDIF.',
                    'LDAP Transfer Center: copy atau transfer entry antar LDAP.',
                    'LDAP Sync Center: sinkronisasi data LDAP ke cache aplikasi.',
                    'Operation Jobs: pusat pelacakan pekerjaan administratif.',
                    'Queue Jobs: memantau pekerjaan Laravel queue.',
                    'LDAP Bulk Operations: operasi LDAP massal.',
                    'LDAP Import Center: import data LDAP dari template, file, atau batch.',
                    'Command Executions: mencatat eksekusi command administratif.',
                ]
            ),

            $this->manualAction(
                'bulk',
                '6. Bulk',
                'heroicon-o-exclamation-triangle',
                'danger',
                '6. LDAP Bulk Operations',
                [
                    'LDAP Bulk Operations digunakan untuk menjalankan operasi LDAP massal berdasarkan Base DN, Custom Target DN, RDN, atau LDAP Filter.',
                    'Fitur ini berbahaya jika digunakan tanpa preview karena dapat mengubah banyak entry LDAP sekaligus.',
                ],
                [
                    'Pilih LDAP Connection.',
                    'Pilih Target Mode.',
                    'Isi Base DN atau Custom Target DN.',
                    'Isi Search Scope.',
                    'Isi Size Limit.',
                    'Isi LDAP Filter.',
                    'Pilih Operation Type.',
                    'Generate Preview.',
                    'Apply hanya jika hasil preview benar.',
                    'Rollback jika tersedia dan diperlukan.',
                    'Contoh filter: (|(uid=usr000046)(uid=usr000047)(uid=usr000048))',
                ]
            ),

            $this->manualAction(
                'import_export',
                '7. Import Export',
                'heroicon-o-arrow-down-tray',
                'success',
                '7. Import, Export, Transfer, Sync',
                [
                    'Menu ini digunakan untuk perpindahan, backup, import, dan refresh data LDAP.',
                ],
                [
                    'LDIF Exports digunakan untuk backup atau export data LDAP.',
                    'LDAP Import Center digunakan untuk import data LDAP dari template, file, atau batch.',
                    'LDAP Transfer Center digunakan untuk copy atau transfer data antar LDAP.',
                    'LDAP Sync Center digunakan untuk refresh cache aplikasi setelah data LDAP berubah.',
                    'Jangan import langsung ke production tanpa test.',
                    'Selalu generate plan atau preview sebelum apply.',
                ]
            ),

            $this->manualAction(
                'observability',
                '8. Logs',
                'heroicon-o-chart-bar',
                'success',
                '8. Observability',
                [
                    'Observability digunakan untuk monitoring, audit, dan troubleshooting.',
                ],
                [
                    'Audit Logs: mencatat siapa melakukan aksi, module, action, status, target DN, before, dan after value.',
                    'Failed Jobs: melihat queue job yang gagal.',
                    'System Logs: melihat error runtime Laravel, LDAP, database, Filament, dan Livewire.',
                    'Health Checks: memantau database, LDAP connection, queue worker, storage, dan log permission.',
                    'Operation Jobs: melihat status operasi, item per DN, total OK, total failed, dan metadata.',
                    'Command Executions: melihat command, stdout, stderr, dan execution result.',
                ]
            ),

            $this->manualAction(
                'ldap_concepts',
                '9. Concepts',
                'heroicon-o-academic-cap',
                'gray',
                '9. LDAP Concepts',
                [
                    'Bagian ini menjelaskan istilah LDAP yang sering dipakai di aplikasi.',
                ],
                [
                    'Base DN: titik awal pencarian LDAP.',
                    'DN: alamat lengkap sebuah entry.',
                    'RDN: bagian pertama dari DN.',
                    'RDN Attribute: attribute pada RDN, misalnya uid.',
                    'RDN Value: nilai RDN, misalnya usr000046.',
                    'LDAP Filter: syarat pencarian LDAP.',
                    'Structural ObjectClass: jenis utama entry LDAP.',
                    'Auxiliary ObjectClass: objectClass tambahan untuk memperluas attribute entry.',
                    'MUST Attribute: attribute wajib.',
                    'MAY Attribute: attribute opsional.',
                ]
            ),

            $this->manualAction(
                'filters',
                '10. Filter',
                'heroicon-o-code-bracket',
                'gray',
                '10. Contoh LDAP Filter',
                [
                    '(objectClass=*)',
                    '(objectClass=inetOrgPerson)',
                    '(uid=usr000046)',
                    '(|(uid=usr000046)(uid=usr000047)(uid=usr000048))',
                    '(&(objectClass=inetOrgPerson)(uid=usr000046))',
                    '(mail=*)',
                    '(objectClass=domainRelatedObject)',
                ],
                []
            ),

            $this->manualAction(
                'troubleshooting',
                '11. Troubleshooting',
                'heroicon-o-wrench-screwdriver',
                'danger',
                '11. Troubleshooting Umum',
                [
                    'Gunakan bagian ini ketika fitur LDAP, queue, import, export, transfer, atau bulk operation bermasalah.',
                ],
                [
                    'LDAP Connection gagal: cek host, port, firewall, bind DN, password, SSL/TLS, dan Base DN.',
                    'Preview tidak menemukan entry: cek Base DN, LDAP Filter, dan Search Scope.',
                    'Apply berhasil tapi Apache Directory Studio belum berubah: refresh entry dan cek LDAP Connection yang dipakai.',
                    'Add ObjectClass gagal: pastikan objectClass auxiliary dan MUST attribute sudah diisi.',
                    'Delete ObjectClass gagal: cek attribute terkait dan Schema Browser.',
                    'Queue Job tidak jalan: cek Queue Jobs, Failed Jobs, dan worker Laravel.',
                    'Error database: cek System Logs, migration, model cast, column type, dan JSON payload.',
                ]
            ),

            $this->manualAction(
                'sop',
                '12. SOP',
                'heroicon-o-clipboard-document-check',
                'success',
                '12. SOP Harian Administrator',
                [
                    'SOP ini digunakan agar administrator tidak langsung menjalankan operasi berbahaya tanpa pengecekan.',
                ],
                [
                    'Login ke aplikasi.',
                    'Cek Dashboard.',
                    'Cek Health Checks.',
                    'Cek Failed Jobs.',
                    'Cek Audit Logs terbaru.',
                    'Jika akan mengubah LDAP, cek LDAP Connection.',
                    'Jalankan sync jika data cache tidak terbaru.',
                    'Buat operasi.',
                    'Generate Preview.',
                    'Validasi target.',
                    'Apply.',
                    'Verifikasi di Apache Directory Studio atau ldapsearch.',
                    'Cek Audit Logs dan Operation Jobs.',
                    'Dokumentasikan hasil.',
                ]
            ),
        ];
    }

    private function manualAction(
        string $name,
        string $label,
        string $icon,
        string $color,
        string $heading,
        array $paragraphs,
        array $bullets
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->modalHeading($heading)
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->modalContent(new HtmlString($this->manualHtml($paragraphs, $bullets)));
    }

    private function manualHtml(array $paragraphs, array $bullets): string
    {
        $html = '<div style="display:grid;gap:14px;">';

        foreach ($paragraphs as $paragraph) {
            $html .= '<div style="padding:16px;border-radius:14px;border:1px solid rgba(148,163,184,.25);background:rgba(15,23,42,.55);line-height:1.8;color:#cbd5e1;">';
            $html .= e($paragraph);
            $html .= '</div>';
        }

        if ($bullets !== []) {
            $html .= '<div style="padding:16px;border-radius:14px;border:1px solid rgba(59,130,246,.35);background:rgba(59,130,246,.08);">';
            $html .= '<div style="font-weight:800;color:#dbeafe;margin-bottom:10px;">Poin Penting</div>';
            $html .= '<ul style="margin:0;padding-left:22px;line-height:1.9;color:#cbd5e1;">';

            foreach ($bullets as $bullet) {
                $html .= '<li>' . e($bullet) . '</li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
