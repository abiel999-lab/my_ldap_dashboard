<?php

namespace App\Filament\Pages\Miscellaneous;

use BackedEnum;
use Filament\Pages\Page;
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
        return <<<TEXT
PETRA LDAP DASHBOARD - USER MANUAL LENGKAP
==========================================

1. Pengantar Aplikasi
---------------------

Petra LDAP Dashboard adalah aplikasi administrasi LDAP berbasis Laravel dan Filament yang digunakan untuk membantu administrator mengelola koneksi LDAP, membaca struktur directory, mengelola user, group, role, unit, schema, import, export, transfer, sync, queue, operation job, audit log, system log, health check, serta integrasi Keycloak.

Aplikasi ini dibuat untuk memudahkan administrator bekerja tanpa selalu memakai command line seperti ldapsearch, ldapmodify, ldapadd, atau ldapdelete secara manual. Namun aplikasi ini tetap harus digunakan dengan hati-hati karena sebagian fitur dapat mengubah data LDAP asli.

Prinsip utama penggunaan aplikasi:

1. Selalu pastikan LDAP Connection yang dipilih benar.
2. Selalu baca Base DN, Target DN, LDAP Filter, dan Search Scope sebelum menjalankan operasi.
3. Untuk operasi massal, selalu jalankan Preview terlebih dahulu.
4. Jangan Apply jika hasil Preview belum sesuai.
5. Gunakan Rollback jika operasi mendukung rollback dan hasil apply perlu dibatalkan.
6. Gunakan Audit Logs, Operation Jobs, Queue Jobs, Failed Jobs, dan System Logs untuk melacak aktivitas.
7. Jangan menjalankan operasi destructive pada OU besar tanpa filter spesifik.
8. Untuk objectClass, pahami perbedaan structural dan auxiliary sebelum menambah atau menghapus objectClass.
9. Untuk attribute, pastikan attribute memang diizinkan oleh objectClass yang dimiliki entry.
10. Untuk koneksi production, hindari eksperimen langsung tanpa testing di LDAP test.

2. Struktur Menu Utama
----------------------

Aplikasi dibagi menjadi 4 kelompok navigasi:

1. Directory Management
   Bagian ini digunakan untuk mengelola dan membaca struktur LDAP. Menu di dalamnya berkaitan dengan LDAP Servers, LDAP Connections, Users, Directory Object Manager, dan Schema Browser.

2. Operations
   Bagian ini digunakan untuk pekerjaan administratif dan pekerjaan massal. Menu di dalamnya mencakup LDIF Exports, LDAP Transfer Center, LDAP Sync Center, Operation Jobs, Queue Jobs, LDAP Bulk Operations, dan LDAP Import Center.

3. Observability
   Bagian ini digunakan untuk monitoring dan pelacakan kejadian. Menu di dalamnya mencakup Audit Logs, Failed Jobs, System Logs, dan Health Checks.

4. Miscellaneous
   Bagian ini berisi fitur pendukung seperti Keycloak dan User Manual.

3. Dashboard
------------

Dashboard adalah halaman awal setelah administrator masuk ke aplikasi. Dashboard digunakan untuk melihat ringkasan kondisi sistem.

Fungsi Dashboard:

1. Melihat ringkasan directory.
2. Melihat ringkasan operasi.
3. Melihat aktivitas security.
4. Melihat audit log terbaru.
5. Melihat operation job terbaru.
6. Membantu administrator menentukan apakah sistem aman sebelum menjalankan operasi LDAP.

Cara menggunakan Dashboard:

1. Masuk ke aplikasi.
2. Buka menu Dashboard.
3. Perhatikan widget ringkasan directory dan operations.
4. Jika ada job gagal, cek Failed Jobs atau Operation Jobs.
5. Jika ada aktivitas mencurigakan, cek Audit Logs.
6. Jika sistem tidak sehat, cek Health Checks.

Dashboard tidak digunakan untuk mengubah LDAP secara langsung. Dashboard digunakan sebagai pusat pemantauan cepat.

4. LDAP Servers
---------------

LDAP Servers digunakan untuk mencatat informasi server LDAP secara umum. Menu ini membantu administrator membedakan server production, development, testing, atau external LDAP.

Fungsi LDAP Servers:

1. Menyimpan identitas server LDAP.
2. Mencatat host, environment, dan informasi umum.
3. Menjadi referensi bagi LDAP Connections.
4. Membantu dokumentasi infrastruktur LDAP.

Kapan digunakan:

1. Saat menambahkan server LDAP baru.
2. Saat memisahkan LDAP production dan test.
3. Saat dokumentasi server diperlukan.
4. Saat ingin melihat daftar server yang tersedia.

Cara penggunaan umum:

1. Buka Directory Management.
2. Pilih LDAP Servers.
3. Klik New LDAP Server jika ingin menambahkan server baru.
4. Isi nama server, host, environment, dan keterangan yang diperlukan.
5. Simpan.
6. Gunakan View untuk melihat detail.
7. Gunakan Edit untuk memperbarui data server.

Catatan:
LDAP Server adalah informasi server. Untuk koneksi teknis seperti host, port, bind DN, password, base DN, dan mapping attribute, gunakan LDAP Connections.

5. LDAP Connections
-------------------

LDAP Connections adalah salah satu menu paling penting. Menu ini menyimpan konfigurasi koneksi ke LDAP server.

Fungsi LDAP Connections:

1. Menyimpan nama koneksi.
2. Menyimpan environment seperti production, staging, local, testing.
3. Menyimpan host LDAP.
4. Menyimpan port LDAP.
5. Menyimpan Base DN.
6. Menyimpan Bind DN.
7. Menyimpan Bind Password.
8. Mengatur SSL atau TLS.
9. Mengatur timeout.
10. Menentukan koneksi aktif atau tidak.
11. Menentukan koneksi default.
12. Menentukan koneksi read-only.
13. Mengatur mapping dinamis untuk user, group, email, UUID, dan membership.

Field penting:

1. Connection Name
   Nama koneksi, misalnya Petra LDAP, Tiny Test LDAP, Local Test LDAP.

2. Environment
   Label lingkungan, misalnya production, staging, testing, local.

3. Host
   Alamat LDAP server, misalnya 127.0.0.1 atau domain LDAP.

4. Port
   Port LDAP. Umumnya 389 untuk LDAP biasa dan 636 untuk LDAPS.

5. Base DN
   Root pencarian LDAP, misalnya dc=petra,dc=ac,dc=id.

6. Bind DN
   Akun LDAP yang digunakan aplikasi untuk login ke LDAP, misalnya cn=admin,dc=petra,dc=ac,dc=id.

7. Bind Password
   Password bind. Saat edit, kosongkan jika tidak ingin mengubah password.

8. Use SSL
   Digunakan jika koneksi menggunakan LDAPS.

9. Use TLS
   Digunakan jika koneksi memakai StartTLS.

10. Active
    Menentukan apakah koneksi bisa dipakai.

11. Default Connection
    Menentukan koneksi utama.

12. Read-only Mode
    Menandai koneksi agar tidak dipakai untuk operasi destructive.

13. User Base DN
    Base DN untuk user, misalnya ou=people,dc=petra,dc=ac,dc=id.

14. Group Base DN
    Base DN untuk group, misalnya ou=groups,dc=petra,dc=ac,dc=id.

15. User Identifier Attribute
    Attribute identitas user. Umumnya uid.

16. User Display Attribute
    Attribute nama tampilan. Umumnya cn.

17. User Email Attribute
    Attribute email. Umumnya mail.

18. Group Member Attribute
    Attribute membership group. Umumnya member.

19. UUID Attribute
    Attribute unique LDAP. Umumnya entryUUID, tetapi LDAP tertentu bisa berbeda.

Cara membuat LDAP Connection:

1. Buka Directory Management.
2. Pilih LDAP Connections.
3. Klik New LDAP Connection.
4. Isi Connection Name.
5. Isi Environment.
6. Isi Host dan Port.
7. Isi Base DN.
8. Isi Bind DN dan Bind Password.
9. Pilih SSL atau TLS jika diperlukan.
10. Aktifkan Active.
11. Pilih Default Connection jika koneksi ini menjadi koneksi utama.
12. Isi mapping user dan group.
13. Simpan.

Cara mengecek LDAP Connection:

1. Buka detail LDAP Connection.
2. Jalankan test koneksi jika action tersedia.
3. Jalankan schema test jika tersedia.
4. Pastikan RootDSE dan subschema bisa dibaca.
5. Jika gagal, periksa host, port, bind DN, password, firewall, SSL/TLS, dan Base DN.

Catatan keamanan:
Jangan mengisi metadata dengan password atau secret tambahan. Password cukup disimpan di field Bind Password.

6. Users
--------

Menu Users digunakan untuk melihat dan mengelola entry user LDAP yang sudah tersinkronisasi ke cache aplikasi.

Fungsi Users:

1. Melihat daftar user LDAP.
2. Melihat DN user.
3. Melihat uid, cn, sn, mail, displayName, objectClass, dan attribute lain.
4. Melakukan sync user dari LDAP.
5. Melakukan operasi terhadap user tertentu.
6. Menghapus user dari LDAP jika action tersedia.
7. Menjalankan operasi LDAP lewat queue agar lebih aman.
8. Melihat detail user tanpa langsung mengubah LDAP.

Kapan digunakan:

1. Saat ingin mencari user berdasarkan uid.
2. Saat ingin melihat attribute user.
3. Saat ingin memastikan user ada di LDAP.
4. Saat ingin mengecek objectClass user.
5. Saat ingin melakukan operasi terhadap satu user.

Cara membaca user:

1. Buka Directory Management.
2. Pilih Users.
3. Gunakan search untuk mencari uid, cn, atau email.
4. Klik View untuk membuka detail.
5. Periksa DN, objectClass, mapped attributes, raw attributes, dan source.

Operasi yang biasanya tersedia pada user:

1. Sync user.
2. Delete LDAP.
3. Add Attribute.
4. Replace Attribute.
5. Remove Attribute.
6. Add ObjectClass.
7. Remove ObjectClass.
8. Rename RDN.
9. Move OU atau Move Parent DN.

Catatan:
Beberapa operasi user berjalan melalui queue. Jika action sudah dikirim tetapi perubahan belum muncul, cek Queue Jobs, Operation Jobs, Command Executions, atau Failed Jobs.

7. Directory Object Manager
---------------------------

Directory Object Manager adalah menu fleksibel untuk melihat dan mengelola object LDAP secara umum, bukan hanya user.

Object yang dapat dikelola:

1. OU.
2. CN.
3. UID.
4. User.
5. Group.
6. Role.
7. Application group.
8. Unit.
9. Service account.
10. Device.
11. Object lain selama masuk ke base DN dan tersinkronisasi.

Fungsi utama:

1. Sync Objects.
2. Create LDAP Object.
3. View object.
4. Sync object tertentu.
5. Delete LDAP object.
6. Add ObjectClass.
7. Remove ObjectClass.
8. Rename RDN.
9. Move Parent DN.
10. Bulk delete selected object.
11. Bulk move selected DN.

Cara sync objects:

1. Buka Directory Object Manager.
2. Klik Sync Objects.
3. Pilih LDAP Connection atau pilih semua active connection.
4. Jalankan.
5. Sistem membuat job untuk membaca LDAP dan memperbarui cache.
6. Cek Operation Jobs untuk progress.

Cara create LDAP object:

1. Klik Create LDAP Object.
2. Pilih LDAP Connection.
3. Isi Parent DN.
4. Isi RDN Attribute, misalnya uid, cn, atau ou.
5. Isi RDN Value.
6. Pilih Structural ObjectClass.
7. Pilih Auxiliary ObjectClasses jika diperlukan.
8. Isi attributes.
9. Submit.
10. Periksa hasil melalui Directory Object Manager dan Apache Directory Studio.

Catatan objectClass:
Structural objectClass menentukan jenis utama entry. Dalam satu entry LDAP umumnya hanya boleh ada satu structural objectClass chain yang valid. Auxiliary objectClass adalah tambahan yang dapat memperluas attribute pada entry.

Cara Add ObjectClass:

1. Buka object.
2. Pilih LDAP Operations.
3. Pilih Add ObjectClass.
4. Pilih objectClass auxiliary.
5. Jika objectClass memiliki MUST attribute, isi value wajib.
6. Submit.
7. Cek Operation Jobs atau Command Executions.
8. Refresh object dari LDAP.

Cara Remove ObjectClass:

1. Pilih object.
2. Pilih LDAP Operations.
3. Pilih Remove ObjectClass.
4. Pilih objectClass auxiliary yang akan dihapus.
5. Sistem dapat menghapus attribute terkait jika action mendukungnya.
6. Submit.
7. Pastikan entry tetap valid setelah objectClass dihapus.

Cara Rename RDN:

1. Pilih object.
2. Klik LDAP Operations.
3. Pilih Rename RDN.
4. Isi RDN Attribute.
5. Isi New RDN Value.
6. Pilih Delete old RDN value jika value lama perlu dihapus.
7. Submit.
8. Pastikan DN baru muncul.

Cara Move Parent DN:

1. Pilih object.
2. Klik LDAP Operations.
3. Pilih Move Parent DN.
4. Isi New Parent DN.
5. Submit.
6. Pastikan parent DN baru valid.
7. Jangan memindahkan OU besar tanpa memahami child object.

Cara bulk delete:

1. Pilih beberapa object dari tabel.
2. Klik bulk action.
3. Pilih Delete Selected From LDAP.
4. Konfirmasi.
5. Cek Operation Jobs.
6. OU yang masih memiliki child kemungkinan ditolak oleh LDAP.

Cara bulk move:

1. Pilih beberapa object dari tabel.
2. Klik bulk action.
3. Pilih Move Selected DN.
4. Isi New Parent DN.
5. Konfirmasi.
6. Cek hasil di Operation Jobs.

8. Directory Explorer
---------------------

Directory Explorer adalah cache pembacaan LDAP yang lebih umum. Menu ini biasanya tidak tampil di navigation utama, tetapi resource-nya tersedia di aplikasi.

Fungsi:

1. Melihat DN.
2. Melihat Parent DN.
3. Melihat RDN.
4. Melihat RDN Attribute dan RDN Value.
5. Melihat Entry UUID jika tersedia.
6. Melihat detected type.
7. Melihat category.
8. Melihat tree level.
9. Melihat child count.
10. Melihat objectClass.
11. Melihat raw attributes.
12. Melihat operational attributes.
13. Melihat source dan last sync.

Cara menggunakan:

1. Jalankan Sync Directory Explorer.
2. Tunggu job selesai.
3. Buka entry.
4. Periksa detail object.
5. Gunakan data ini untuk memahami struktur LDAP sebelum menjalankan operasi.

Directory Explorer bersifat baca data/cache. Untuk operasi perubahan gunakan Directory Object Manager atau LDAP Bulk Operations.

9. Schema Browser
-----------------

Schema Browser digunakan untuk melihat schema LDAP.

Fungsi:

1. Melihat objectClass.
2. Melihat attributeType.
3. Melihat structural objectClass.
4. Melihat auxiliary objectClass.
5. Melihat MUST attribute.
6. Melihat MAY attribute.
7. Melihat syntax attribute.
8. Melihat single-value atau multi-value.
9. Menjadi referensi sebelum membuat object atau menambah attribute.
10. Menjadi sumber dropdown objectClass dan attribute pada beberapa fitur.

Pengertian penting:

1. ObjectClass
   Aturan yang menentukan jenis entry dan attribute yang boleh atau wajib dimiliki.

2. Structural ObjectClass
   Jenis utama entry. Contoh user biasanya memakai inetOrgPerson sebagai structural.

3. Auxiliary ObjectClass
   ObjectClass tambahan yang memperluas entry. Contoh domainRelatedObject, posixAccount, shadowAccount, atau objectClass custom.

4. MUST Attribute
   Attribute wajib. Jika objectClass ditambahkan tetapi MUST attribute tidak diisi, LDAP akan menolak perubahan.

5. MAY Attribute
   Attribute opsional. Boleh diisi jika diperlukan.

6. Attribute Syntax
   Tipe data attribute, misalnya Directory String, IA5 String, Integer, Boolean.

Cara menggunakan Schema Browser:

1. Buka Schema Browser.
2. Cari objectClass atau attribute.
3. Baca type objectClass.
4. Baca MUST dan MAY attribute.
5. Baca syntax attribute.
6. Gunakan informasi itu sebelum membuat object, add objectClass, atau add attribute.

Contoh:
Jika objectClass domainRelatedObject memiliki MUST associatedDomain, maka saat menambahkan domainRelatedObject ke user harus mengisi associatedDomain, misalnya alumni.petra.ac.id.

10. Applications
----------------

Applications adalah registry aplikasi yang dibaca dari group atau entry LDAP tertentu.

Fungsi:

1. Melihat application key.
2. Melihat application name.
3. Melihat application type.
4. Melihat integration type.
5. Melihat environment.
6. Melihat allowed group.
7. Melihat required role.
8. Melihat resolved user.
9. Melihat status aplikasi.
10. Melihat OIDC, SAML, dan API access flags jika tersedia.

Cara menggunakan:

1. Buka application entry jika tersedia dari menu atau link.
2. Jalankan Sync LDAP Applications jika action tersedia.
3. Baca Application Identity.
4. Baca Access Summary.
5. Baca Integration Flags.
6. Baca Access Rules.
7. Gunakan data ini untuk memeriksa akses aplikasi.

Aplikasi ini berguna untuk memastikan group dan role LDAP sudah sesuai dengan kebutuhan SSO, OIDC, SAML, atau API access.

11. Groups
----------

Groups digunakan untuk melihat group LDAP.

Fungsi:

1. Melihat group DN.
2. Melihat cn.
3. Melihat objectClass.
4. Melihat member.
5. Melihat status.
6. Melihat source dan last seen.
7. Menggunakan group sebagai basis aplikasi, role, atau akses.

Cara menggunakan:

1. Buka group list jika tersedia.
2. Cari group berdasarkan cn atau DN.
3. Klik View.
4. Periksa member.
5. Periksa objectClass.
6. Periksa apakah group masih aktif di LDAP.

Catatan:
Jika group dipakai oleh aplikasi atau role, hati-hati saat menghapus atau memindahkan group.

12. Roles
---------

Roles digunakan untuk melihat role LDAP yang dipetakan dari group atau entry tertentu.

Fungsi:

1. Melihat role key.
2. Melihat role name.
3. Melihat role DN.
4. Melihat resolved user.
5. Melihat source group.
6. Membantu mapping akses aplikasi.

Cara menggunakan:

1. Buka role entries jika tersedia.
2. Cari role berdasarkan nama atau key.
3. Buka detail.
4. Periksa source DN.
5. Periksa resolved user.
6. Gunakan role untuk audit akses aplikasi.

13. Units / OU
--------------

Units atau OU digunakan untuk melihat unit organisasi LDAP.

Fungsi:

1. Melihat OU.
2. Melihat parent DN.
3. Melihat child count.
4. Melihat status.
5. Memahami struktur organisasi.
6. Menjadi target move atau target filter operasi.

Cara menggunakan:

1. Buka unit entries jika tersedia.
2. Cari OU.
3. Buka detail.
4. Periksa parent dan child.
5. Jangan menghapus OU yang masih punya child.

Contoh OU:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

14. LDIF Exports
----------------

LDIF Exports digunakan untuk mengekspor data LDAP menjadi file LDIF.

Fungsi:

1. Membuat export batch.
2. Memilih LDAP Connection.
3. Menentukan Base DN.
4. Menentukan target export.
5. Menentukan Search Scope.
6. Menentukan LDAP Filter.
7. Menentukan attribute yang diekspor.
8. Menentukan size limit.
9. Membuat preview/export.
10. Download hasil LDIF jika output tersedia.

Field penting:

1. Export Name
   Nama batch export.

2. LDAP Connection
   Koneksi LDAP sumber.

3. Base DN
   Base pencarian.

4. Export What
   Target export, misalnya full base DN, specific OU, CN, UID, atau custom DN.

5. Search Scope
   Base, one level, atau full subtree.

6. RDN Attribute
   Attribute RDN seperti ou, cn, uid.

7. RDN Value
   Nilai RDN seperti alumni, admin, usr000046.

8. Custom Target DN
   DN manual jika ingin target spesifik.

9. LDAP Filter
   Filter LDAP, misalnya (objectClass=*) atau (&(objectClass=inetOrgPerson)(uid=usr000046)).

10. Attributes
    Daftar attribute yang diekspor, misalnya * atau cn uid mail objectClass.

11. Size Limit
    Batas jumlah entry.

Cara export OU:

1. Buka LDIF Exports.
2. Klik New LDIF Export.
3. Isi Export Name.
4. Pilih LDAP Connection.
5. Isi Base DN.
6. Pilih Export What.
7. Isi target OU atau Custom Target DN.
8. Isi LDAP Filter.
9. Isi Attributes.
10. Simpan.
11. Jalankan preview/export jika action tersedia.
12. Download file LDIF jika output tersedia.

Contoh filter:
(objectClass=*)

Contoh export user alumni tertentu:
Base DN:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

LDAP Filter:
(|(uid=usr000046)(uid=usr000047)(uid=usr000048))

15. LDAP Transfer Center
------------------------

LDAP Transfer Center digunakan untuk memindahkan atau menyalin entry dari satu LDAP ke LDAP lain.

Fungsi:

1. Memilih source LDAP.
2. Memilih target LDAP.
3. Menentukan source base DN.
4. Menentukan custom source DN.
5. Menentukan filter source.
6. Menentukan target parent DN.
7. Menentukan strategi target DN.
8. Menentukan collision strategy.
9. Menentukan attribute yang dikecualikan.
10. Membuat preview transfer.
11. Menjalankan transfer jika sudah aman.
12. Melihat status transfer.

Kapan digunakan:

1. Migrasi user dari LDAP production ke test.
2. Copy data dari external LDAP ke local LDAP.
3. Transfer OU tertentu.
4. Transfer subset user berdasarkan filter.
5. Membuat environment testing.

Aturan aman:

1. Jangan transfer langsung tanpa preview.
2. Jangan transfer userPassword jika tidak diperlukan.
3. Exclude operational attributes seperti entryUUID, entryCSN, createTimestamp, creatorsName, modifyTimestamp, modifiersName, structuralObjectClass.
4. Pastikan target parent DN sudah ada.
5. Pastikan target LDAP bukan production jika hanya testing.

Contoh transfer:

Source LDAP:
Petra LDAP

Target LDAP:
Tiny Test LDAP

Source Base DN:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

Filter:
(|(uid=usr000046)(uid=usr000047)(uid=usr000048))

Target Parent DN:
ou=transfer-target,dc=test,dc=local

16. LDAP Sync Center
-------------------

LDAP Sync Center digunakan untuk menjalankan sinkronisasi data LDAP ke cache aplikasi atau ke struktur internal aplikasi.

Fungsi:

1. Membaca data dari LDAP.
2. Membuat job sync.
3. Memperbarui cache user, group, role, application, unit, atau directory entries.
4. Mencatat status sync.
5. Menjadi dasar data untuk table view.

Kapan digunakan:

1. Setelah data LDAP berubah di luar aplikasi.
2. Setelah import atau transfer.
3. Setelah operasi bulk.
4. Sebelum audit data.
5. Saat tabel aplikasi tidak sesuai dengan Apache Directory Studio.

Cara menggunakan:

1. Buka LDAP Sync Center.
2. Buat atau pilih batch sync.
3. Tentukan LDAP Connection.
4. Tentukan Base DN.
5. Tentukan Filter.
6. Jalankan sync.
7. Pantau Operation Jobs.
8. Jika gagal, cek Failed Jobs dan System Logs.

17. Operation Jobs
------------------

Operation Jobs adalah pusat pelacakan pekerjaan administratif.

Fungsi:

1. Melihat job operasi.
2. Melihat status job.
3. Melihat module.
4. Melihat action.
5. Melihat target DN.
6. Melihat metadata.
7. Melihat item job.
8. Melihat log job.

Status umum:

1. queued
2. running
3. success
4. failed
5. partial_failed
6. skipped

Cara menggunakan:

1. Buka Operation Jobs.
2. Cari job berdasarkan module atau target.
3. Klik View.
4. Baca detail job.
5. Buka Items untuk melihat item per entry.
6. Buka Logs untuk melihat proses.

Gunakan menu ini setiap kali menjalankan operasi queue, import, export, transfer, sync, atau mutation.

18. Queue Jobs
--------------

Queue Jobs digunakan untuk memantau pekerjaan Laravel queue.

Fungsi:

1. Melihat job yang sedang menunggu.
2. Melihat job yang sedang berjalan jika tersedia.
3. Melihat queue name.
4. Membantu memahami apakah worker berjalan.
5. Membantu debugging operasi yang belum selesai.

Cara menggunakan:

1. Buka Queue Jobs.
2. Periksa daftar job.
3. Jika job menumpuk, pastikan queue worker berjalan.
4. Jika job gagal, cek Failed Jobs.
5. Jika job stuck, restart queue worker sesuai SOP server.

Command Laravel yang berkaitan:
queue:work
queue:restart
queue:failed
queue:retry
queue:clear

19. LDAP Bulk Operations
------------------------

LDAP Bulk Operations digunakan untuk menjalankan operasi LDAP massal berdasarkan Base DN, Custom Target DN, RDN, atau LDAP Filter.

Fitur utama:

1. Create operation melalui modal.
2. Pilih LDAP Connection.
3. Pilih Target Mode.
4. Isi Base DN atau Custom Target DN.
5. Isi Search Scope.
6. Isi Size Limit.
7. Isi LDAP Filter.
8. Pilih Operation Type.
9. Pilih objectClass auxiliary dari schema.
10. Isi MUST attribute jika diperlukan.
11. Generate Preview.
12. Apply.
13. Rollback.

Target Mode:

1. Base DN + LDAP Filter
   Digunakan untuk mencari banyak entry di bawah base DN.

2. Custom Target DN
   Digunakan untuk target DN spesifik.

3. RDN Attribute + Value
   Digunakan untuk target berbasis RDN seperti uid, cn, ou.

Search Scope:

1. Base
   Hanya membaca base DN itu sendiri.

2. One Level
   Membaca child satu level di bawah base DN.

3. Full Subtree
   Membaca semua child di bawah base DN.

Operation Type yang direncanakan:

1. Add ObjectClass
   Menambahkan auxiliary objectClass ke banyak entry.

2. Delete ObjectClass
   Menghapus auxiliary objectClass dari banyak entry.

3. Add Attribute
   Menambahkan attribute ke banyak entry.

4. Delete Attribute
   Menghapus attribute dari banyak entry.

5. Move to OU
   Memindahkan entry ke parent OU lain.

6. Delete Entry
   Menghapus entry yang cocok filter.

Status implementasi real saat ini:
Real apply yang sudah diuji dan berhasil adalah Add ObjectClass ke LDAP asli. Generate Preview membaca LDAP asli, Apply menjalankan ldap_mod_add, dan Rollback menggunakan rollback payload untuk menghapus objectClass/attribute yang ditambahkan.

Cara Add ObjectClass massal:

1. Buka LDAP Bulk Operations.
2. Klik New LDAP Bulk Operation.
3. Isi Operation Name.
4. Pilih LDAP Connection.
5. Pilih Target Mode: Base DN + LDAP Filter.
6. Isi Base DN.
7. Pilih Search Scope: Full subtree.
8. Isi Size Limit.
9. Isi LDAP Filter.
10. Pilih Operation Type: Add ObjectClass.
11. Pilih ObjectClass Name.
12. Jika muncul MUST attribute, isi value-nya.
13. Pastikan Skip invalid entries aktif.
14. Pastikan Require preview before apply aktif.
15. Klik Create.
16. Buka record.
17. Klik Generate Preview.
18. Pastikan entry_count dan DN sudah benar.
19. Klik Apply.
20. Refresh Apache Directory Studio untuk memverifikasi.
21. Jika salah, klik Rollback.

Contoh kasus alumni:

Operation Name:
Bulk Add domainRelatedObject Alumni Above 45

LDAP Connection:
Petra LDAP

Target Mode:
Base DN + LDAP Filter

Base DN:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

Search Scope:
Full subtree

Size Limit:
100

LDAP Filter:
(|(uid=usr000046)(uid=usr000047)(uid=usr000048))

Operation Type:
Add ObjectClass

ObjectClass:
domainRelatedObject

MUST Attribute:
associatedDomain = alumni.petra.ac.id

Hasil setelah Apply:
objectClass bertambah menjadi domainRelatedObject.
Attribute associatedDomain bertambah dengan value alumni.petra.ac.id.

Catatan:
LDAP tidak memakai uuid aplikasi. Entry LDAP tetap memakai uid, cn, ou, atau attribute lain sesuai DN. Kolom uuid yang ada di database hanya untuk record internal aplikasi.

20. LDAP Import Center
----------------------

LDAP Import Center digunakan untuk import data LDAP dari template, file, atau batch import.

Fungsi umum:

1. Membuat import batch.
2. Membaca row import.
3. Membuat apply plan.
4. Mengecek konflik.
5. Menjalankan perubahan secara bertahap.
6. Menyimpan hasil import.
7. Melihat row berhasil, gagal, atau skipped.

Menu terkait:

1. Import Templates
2. Import Batches
3. Import Apply Plans
4. Import Rows jika relation manager tersedia.

Cara menggunakan import:

1. Buat template import jika diperlukan.
2. Buat import batch.
3. Upload atau masukkan data.
4. Generate plan.
5. Periksa plan.
6. Apply hanya jika plan aman.
7. Cek hasil di Operation Jobs dan Audit Logs.

Aturan aman import:

1. Jangan import langsung ke production tanpa test.
2. Pastikan DN tidak bentrok.
3. Pastikan objectClass sesuai schema.
4. Pastikan MUST attribute lengkap.
5. Gunakan preview/apply plan.
6. Simpan backup LDIF sebelum import besar.

21. Command Executions
----------------------

Command Executions digunakan untuk mencatat eksekusi command administratif, terutama command yang berkaitan dengan LDAP operation.

Fungsi:

1. Melihat command yang dijalankan.
2. Melihat status command.
3. Melihat stdout.
4. Melihat stderr.
5. Melihat preview command.
6. Melihat target DN.
7. Melihat error message.
8. Menjadi audit teknis untuk operasi LDAP.

Cara menggunakan:

1. Buka Command Executions jika tersedia.
2. Cari command berdasarkan status atau operation.
3. Buka detail.
4. Baca command, stdout, stderr, dan metadata.
5. Gunakan informasi ini untuk debugging.

22. Bulk LDAP Operation Items
-----------------------------

Bulk LDAP Operation Items digunakan untuk melihat item-item dari bulk operation.

Fungsi:

1. Melihat target DN per item.
2. Melihat planned action.
3. Melihat status per item.
4. Melihat error per DN.
5. Melihat hasil apply per DN.

Kapan digunakan:

1. Setelah bulk operation.
2. Saat sebagian entry berhasil dan sebagian gagal.
3. Saat ingin mengetahui DN mana yang skipped.
4. Saat ingin debugging filter yang terlalu luas atau terlalu sempit.

23. Audit Logs
--------------

Audit Logs digunakan untuk mencatat aktivitas administrator.

Fungsi:

1. Melihat siapa melakukan aksi.
2. Melihat module.
3. Melihat action.
4. Melihat status.
5. Melihat target type.
6. Melihat target DN.
7. Melihat before dan after value jika tersedia.
8. Membantu investigasi perubahan.

Cara menggunakan:

1. Buka Observability.
2. Pilih Audit Logs.
3. Cari berdasarkan module, action, user, atau target.
4. Klik View untuk detail.
5. Gunakan log ini sebagai bukti aktivitas.

Contoh aktivitas yang dapat muncul:

1. Create LDAP Connection.
2. Update LDAP Connection.
3. Delete LDAP Connection.
4. Preview bulk operation.
5. Apply bulk operation.
6. Rollback bulk operation.
7. Sync LDAP.
8. Import/Export action.

24. Failed Jobs
---------------

Failed Jobs digunakan untuk melihat queue job yang gagal.

Fungsi:

1. Melihat job gagal.
2. Melihat connection.
3. Melihat queue.
4. Melihat payload.
5. Melihat exception.
6. Membantu debugging worker.

Cara menggunakan:

1. Buka Failed Jobs.
2. Cari job terbaru.
3. Klik View.
4. Baca exception.
5. Perbaiki penyebab error.
6. Retry job jika aman.
7. Jangan retry destructive job tanpa memahami akibatnya.

Penyebab umum failed job:

1. LDAP connection gagal.
2. Bind DN salah.
3. Password salah.
4. Base DN salah.
5. ObjectClass tidak valid.
6. MUST attribute kurang.
7. Target DN tidak ditemukan.
8. LDAP menolak delete karena OU masih punya child.
9. Queue worker mati.
10. Timeout.

25. System Logs
---------------

System Logs digunakan untuk melihat log aplikasi.

Fungsi:

1. Melihat error runtime.
2. Melihat exception Laravel.
3. Melihat error database.
4. Melihat error LDAP.
5. Melihat error Filament atau Livewire.
6. Membantu troubleshooting.

Cara menggunakan:

1. Buka Observability.
2. Pilih System Logs.
3. Cari error terbaru.
4. Baca pesan error.
5. Cocokkan dengan aksi terakhir yang dilakukan.
6. Jika error dari LDAP, cek LDAP Connection.
7. Jika error dari database, cek migration atau column.
8. Jika error dari queue, cek Failed Jobs.

26. Health Checks
-----------------

Health Checks digunakan untuk memantau kesehatan sistem.

Fungsi:

1. Melihat status health check.
2. Melihat service yang dicek.
3. Melihat pesan error.
4. Melihat waktu check.
5. Membantu memastikan sistem siap dipakai.

Kapan digunakan:

1. Sebelum operasi besar.
2. Setelah deploy.
3. Setelah restart service.
4. Setelah migration.
5. Saat aplikasi terasa lambat.
6. Saat LDAP operation gagal.

Hal yang perlu dicek:

1. Database.
2. LDAP connection.
3. Queue worker.
4. Storage.
5. Log permission.
6. Keycloak jika login tergantung Keycloak.

27. Keycloak
------------

Menu Keycloak digunakan sebagai shortcut ke Keycloak Admin Console.

Fungsi:

1. Membuka Keycloak Admin Console.
2. Membantu administrator masuk ke panel Keycloak.
3. Memudahkan pengecekan realm, client, role, group, user federation, dan authentication flow.

Cara menggunakan:

1. Buka Miscellaneous.
2. Pilih Keycloak.
3. Klik Open Keycloak Admin Console.
4. Login ke Keycloak.
5. Kelola realm, client, role, atau user federation sesuai kebutuhan.

Catatan:
Menu ini hanya shortcut. Perubahan Keycloak dilakukan di Keycloak Admin Console, bukan langsung di Petra LDAP Dashboard.

28. User Manual
---------------

User Manual adalah halaman dokumentasi internal aplikasi.

Fungsi:

1. Menjelaskan fungsi setiap menu.
2. Menjelaskan cara pakai fitur penting.
3. Menjelaskan aturan aman.
4. Menjelaskan alur operasi.
5. Menjelaskan troubleshooting dasar.
6. Membantu administrator baru memahami sistem.

Jika fitur aplikasi berubah, User Manual harus diperbarui agar tidak menyesatkan.

29. Alur Aman Sebelum Operasi LDAP
----------------------------------

Sebelum melakukan operasi yang mengubah LDAP:

1. Pastikan LDAP Connection benar.
2. Pastikan Base DN benar.
3. Pastikan LDAP Filter benar.
4. Pastikan Search Scope tidak terlalu luas.
5. Pastikan Size Limit sesuai.
6. Pastikan objectClass dan attribute sesuai schema.
7. Pastikan MUST attribute lengkap.
8. Jalankan Preview.
9. Baca hasil Preview.
10. Pastikan jumlah entry sesuai harapan.
11. Apply hanya jika preview benar.
12. Cek Apache Directory Studio atau ldapsearch.
13. Cek Audit Logs.
14. Cek Operation Jobs.
15. Simpan catatan hasil.

30. Contoh LDAP Filter
----------------------

Semua entry:
(objectClass=*)

Semua user inetOrgPerson:
(objectClass=inetOrgPerson)

User dengan uid tertentu:
(uid=usr000046)

Beberapa uid:
(|(uid=usr000046)(uid=usr000047)(uid=usr000048))

User yang punya mail:
(mail=*)

User yang berada di OU alumni:
Gunakan Base DN:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

Lalu filter:
(objectClass=inetOrgPerson)

User dengan objectClass tertentu:
(objectClass=petraPerson)

Gabungan objectClass dan uid:
(&(objectClass=inetOrgPerson)(uid=usr000046))

31. Perbedaan Base DN, DN, RDN, dan Filter
------------------------------------------

Base DN:
Titik awal pencarian LDAP.

Contoh:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

DN:
Alamat lengkap sebuah entry.

Contoh:
uid=usr000046,ou=alumni,ou=people,dc=petra,dc=ac,dc=id

RDN:
Bagian pertama dari DN.

Contoh:
uid=usr000046

RDN Attribute:
Attribute pada RDN.

Contoh:
uid

RDN Value:
Nilai RDN.

Contoh:
usr000046

LDAP Filter:
Syarat pencarian.

Contoh:
(uid=usr000046)

32. Perbedaan Preview, Apply, Rollback
--------------------------------------

Preview:
Membaca target dan membuat rencana perubahan. Preview tidak boleh mengubah LDAP.

Apply:
Menjalankan perubahan ke LDAP asli. Apply hanya boleh dilakukan setelah preview benar.

Rollback:
Membatalkan perubahan yang sudah dilakukan jika rollback payload tersedia. Rollback tidak selalu bisa mengembalikan semua kondisi jika data berubah manual setelah apply.

Aturan:
1. Preview dulu.
2. Apply setelah yakin.
3. Rollback hanya jika diperlukan.
4. Cek hasil di LDAP asli.
5. Cek log setelah operasi.

33. Troubleshooting Umum
------------------------

Masalah: LDAP Connection gagal.
Penyebab:
Host salah, port salah, firewall, bind DN salah, password salah, TLS/SSL salah.

Solusi:
Cek host, port, bind DN, password, SSL/TLS, dan coba schema test.

Masalah: Preview tidak menemukan entry.
Penyebab:
Base DN salah, filter salah, scope terlalu sempit.

Solusi:
Coba filter (objectClass=*), naikkan scope ke Full subtree, pastikan Base DN benar.

Masalah: Apply berhasil tetapi Apache Directory Studio belum berubah.
Penyebab:
Belum refresh, koneksi berbeda, LDAP berbeda, atau apply masih safe mode pada fitur tertentu.

Solusi:
Refresh entry, cek LDAP Connection yang dipakai, cek Execution Result JSON, cek Audit Logs.

Masalah: Add ObjectClass gagal.
Penyebab:
ObjectClass bukan auxiliary, MUST attribute kurang, attribute tidak sesuai schema.

Solusi:
Cek Schema Browser, isi MUST attribute, gunakan auxiliary objectClass.

Masalah: Delete ObjectClass gagal.
Penyebab:
Attribute yang terkait masih ada, objectClass masih dibutuhkan, entry jadi invalid.

Solusi:
Hapus attribute terkait terlebih dahulu jika aman, cek schema, jangan hapus structural objectClass.

Masalah: Queue job tidak jalan.
Penyebab:
Worker mati atau queue backlog.

Solusi:
Cek Queue Jobs, Failed Jobs, dan jalankan queue worker sesuai SOP.

Masalah: Error database.
Penyebab:
Kolom belum sesuai migration, data terlalu panjang, uuid kosong, json tidak valid.

Solusi:
Cek System Logs, cek migration, cek model cast, cek column type.

34. SOP Operasi Harian Administrator
------------------------------------

1. Login ke aplikasi.
2. Cek Dashboard.
3. Cek Health Checks.
4. Cek Failed Jobs.
5. Cek Audit Logs terbaru.
6. Jika akan mengubah LDAP, cek LDAP Connection.
7. Jalankan sync jika data cache tidak terbaru.
8. Buat operasi.
9. Generate Preview.
10. Validasi target.
11. Apply.
12. Verifikasi di Apache Directory Studio.
13. Cek Audit Logs dan Operation Jobs.
14. Dokumentasikan hasil.

35. SOP Sebelum Operasi Massal
------------------------------

1. Export LDIF backup terlebih dahulu.
2. Pastikan koneksi bukan koneksi yang salah.
3. Gunakan filter spesifik.
4. Gunakan Size Limit kecil untuk test.
5. Test pada 1 sampai 3 entry dulu.
6. Jika berhasil, naikkan jumlah target.
7. Jangan langsung operasi ke ribuan entry.
8. Pastikan rollback tersedia.
9. Pantau logs.
10. Simpan hasil audit.

36. Penutup
-----------

Petra LDAP Dashboard dibuat untuk mempercepat administrasi LDAP, tetapi administrator tetap wajib memahami DN, RDN, objectClass, attribute, schema, filter, dan konsekuensi operasi LDAP. Fitur seperti Import, Transfer, Sync, dan Bulk Operations sangat membantu, tetapi harus digunakan dengan disiplin: preview terlebih dahulu, validasi target, apply setelah yakin, dan cek audit log setelah selesai.

Manual ini harus dianggap sebagai panduan operasional. Jika ada fitur baru, perubahan menu, atau perubahan workflow, halaman ini harus diperbarui agar selalu sesuai dengan aplikasi yang berjalan.
TEXT;
    }
}
