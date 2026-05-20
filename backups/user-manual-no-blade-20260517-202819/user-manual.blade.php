<x-filament-panels::page>
    <div class="space-y-8">

        {{-- HERO --}}
        <div class="rounded-2xl border border-white/10 bg-gray-900/60 p-8 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="space-y-2">
                    <div class="inline-flex items-center gap-2 rounded-full border border-primary-500/30 bg-primary-500/10 px-3 py-1 text-xs font-semibold text-primary-300">
                        <x-heroicon-o-book-open class="h-4 w-4" />
                        PETRA LDAP DASHBOARD
                    </div>

                    <h1 class="text-3xl font-bold tracking-tight text-white">
                        User Manual Lengkap
                    </h1>

                    <p class="max-w-3xl text-sm leading-6 text-gray-400">
                        Panduan penggunaan fitur LDAP Dashboard untuk administrator: Directory Management,
                        Operations, Observability, Keycloak, Bulk Operations, Import, Export, Transfer, Sync,
                        Queue Jobs, Audit Logs, dan troubleshooting.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                        <div class="text-xs text-gray-400">Mode</div>
                        <div class="mt-1 font-semibold text-white">Admin Guide</div>
                    </div>

                    <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                        <div class="text-xs text-gray-400">Safety</div>
                        <div class="mt-1 font-semibold text-warning-400">Preview First</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- QUICK CARDS --}}
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <a href="#directory-management" class="rounded-2xl border border-white/10 bg-gray-900/60 p-5 transition hover:border-primary-500/50 hover:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-primary-500/10 p-3 text-primary-400">
                        <x-heroicon-o-folder class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-semibold text-white">Directory Management</div>
                        <div class="text-xs text-gray-400">LDAP, Users, Objects, Schema</div>
                    </div>
                </div>
            </a>

            <a href="#operations" class="rounded-2xl border border-white/10 bg-gray-900/60 p-5 transition hover:border-primary-500/50 hover:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-warning-500/10 p-3 text-warning-400">
                        <x-heroicon-o-command-line class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-semibold text-white">Operations</div>
                        <div class="text-xs text-gray-400">Import, Export, Transfer, Bulk</div>
                    </div>
                </div>
            </a>

            <a href="#observability" class="rounded-2xl border border-white/10 bg-gray-900/60 p-5 transition hover:border-primary-500/50 hover:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-success-500/10 p-3 text-success-400">
                        <x-heroicon-o-chart-bar class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-semibold text-white">Observability</div>
                        <div class="text-xs text-gray-400">Audit, Logs, Health, Failed Jobs</div>
                    </div>
                </div>
            </a>

            <a href="#troubleshooting" class="rounded-2xl border border-white/10 bg-gray-900/60 p-5 transition hover:border-primary-500/50 hover:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-danger-500/10 p-3 text-danger-400">
                        <x-heroicon-o-wrench-screwdriver class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-semibold text-white">Troubleshooting</div>
                        <div class="text-xs text-gray-400">Error, Queue, LDAP Failure</div>
                    </div>
                </div>
            </a>
        </div>

        {{-- WARNING --}}
        <div class="rounded-2xl border border-warning-500/20 bg-warning-500/10 p-5">
            <div class="flex gap-3">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-6 w-6 shrink-0 text-warning-400" />
                <div>
                    <h3 class="font-semibold text-warning-300">Prinsip Aman Penggunaan</h3>
                    <p class="mt-1 text-sm leading-6 text-warning-100/80">
                        Jangan menjalankan operasi massal langsung ke LDAP production tanpa preview.
                        Selalu cek LDAP Connection, Base DN, Filter, Search Scope, dan hasil Preview sebelum Apply.
                    </p>
                </div>
            </div>
        </div>

        {{-- MAIN GRID --}}
        <div class="grid gap-6 xl:grid-cols-[280px_1fr]">

            {{-- SIDEBAR --}}
            <div class="hidden xl:block">
                <div class="sticky top-6 space-y-2 rounded-2xl border border-white/10 bg-gray-900/60 p-4">
                    <div class="px-3 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Manual Sections
                    </div>

                    @foreach ([
                        'pengantar' => '1. Pengantar',
                        'dashboard' => '2. Dashboard',
                        'directory-management' => '3. Directory Management',
                        'operations' => '4. Operations',
                        'bulk-operations' => '5. LDAP Bulk Operations',
                        'import-export' => '6. Import & Export',
                        'observability' => '7. Observability',
                        'ldap-concepts' => '8. LDAP Concepts',
                        'troubleshooting' => '9. Troubleshooting',
                        'sop' => '10. SOP Harian',
                    ] as $id => $label)
                        <a href="#{{ $id }}" class="block rounded-xl px-3 py-2 text-sm text-gray-400 transition hover:bg-white/5 hover:text-white">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- CONTENT --}}
            <div class="space-y-6">

                <section id="pengantar" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">1. Pengantar Aplikasi</h2>
                    <p class="mt-3 text-sm leading-7 text-gray-300">
                        Petra LDAP Dashboard adalah aplikasi administrasi LDAP berbasis Laravel dan Filament
                        yang digunakan untuk membantu administrator mengelola koneksi LDAP, membaca struktur directory,
                        mengelola user, group, role, unit, schema, import, export, transfer, sync, queue,
                        operation job, audit log, system log, health check, serta integrasi Keycloak.
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <h3 class="font-semibold text-white">Tujuan Utama</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-400">
                                Memudahkan administrator bekerja tanpa selalu memakai command line seperti
                                ldapsearch, ldapmodify, ldapadd, atau ldapdelete secara manual.
                            </p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <h3 class="font-semibold text-white">Catatan Penting</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-400">
                                Sebagian fitur dapat mengubah data LDAP asli. Gunakan preview, audit log,
                                operation job, dan rollback jika tersedia.
                            </p>
                        </div>
                    </div>
                </section>

                <section id="dashboard" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">2. Dashboard</h2>
                    <p class="mt-3 text-sm leading-7 text-gray-300">
                        Dashboard adalah halaman awal setelah administrator masuk ke aplikasi.
                        Dashboard digunakan untuk melihat ringkasan kondisi sistem sebelum menjalankan operasi LDAP.
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-3">
                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <div class="text-xs text-gray-500">Fungsi</div>
                            <div class="mt-1 font-semibold text-white">Directory Summary</div>
                            <p class="mt-2 text-sm text-gray-400">Melihat jumlah aplikasi, unit, schema, dan directory entries.</p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <div class="text-xs text-gray-500">Fungsi</div>
                            <div class="mt-1 font-semibold text-white">Operations Summary</div>
                            <p class="mt-2 text-sm text-gray-400">Melihat queued jobs, running jobs, failed operations, dan failed queue jobs.</p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <div class="text-xs text-gray-500">Fungsi</div>
                            <div class="mt-1 font-semibold text-white">Security Summary</div>
                            <p class="mt-2 text-sm text-gray-400">Melihat audit logs, command executions, import batches, dan LDIF exports.</p>
                        </div>
                    </div>

                    <div class="mt-5 rounded-xl border border-primary-500/20 bg-primary-500/10 p-4">
                        <h3 class="font-semibold text-primary-300">Cara Pakai</h3>
                        <ol class="mt-3 space-y-2 text-sm leading-6 text-gray-300">
                            <li>1. Masuk ke aplikasi.</li>
                            <li>2. Buka menu Dashboard.</li>
                            <li>3. Cek Failed Operations dan Failed Queue Jobs.</li>
                            <li>4. Jika ada error, buka Operation Jobs, Failed Jobs, Audit Logs, atau System Logs.</li>
                            <li>5. Jalankan operasi LDAP hanya jika kondisi sistem aman.</li>
                        </ol>
                    </div>
                </section>

                <section id="directory-management" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">3. Directory Management</h2>
                    <p class="mt-3 text-sm leading-7 text-gray-300">
                        Directory Management digunakan untuk mengelola dan membaca struktur LDAP.
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        @foreach ([
                            ['LDAP Servers', 'Mencatat informasi server LDAP seperti production, staging, testing, atau external LDAP.'],
                            ['LDAP Connections', 'Menyimpan host, port, base DN, bind DN, password, SSL/TLS, mapping user, mapping group, dan status default connection.'],
                            ['Users', 'Melihat dan mengelola user LDAP, termasuk DN, uid, cn, mail, objectClass, dan attribute lain.'],
                            ['Directory Object Manager', 'Mengelola object LDAP umum seperti OU, CN, UID, group, role, application group, service account, dan object lain.'],
                            ['Schema Browser', 'Melihat objectClass, attributeType, MUST, MAY, syntax, structural, dan auxiliary schema.'],
                            ['Directory Explorer', 'Membaca struktur LDAP secara generic dari base DN dan menyimpan hasil sync sebagai cache.'],
                        ] as [$title, $desc])
                            <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <h3 class="font-semibold text-white">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-400">{{ $desc }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">3.1 LDAP Connections</h2>

                    <div class="mt-5 overflow-hidden rounded-xl border border-white/10">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-950/70 text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Field</th>
                                    <th class="px-4 py-3">Fungsi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                @foreach ([
                                    ['Connection Name', 'Nama koneksi LDAP.'],
                                    ['Environment', 'Label lingkungan seperti production, staging, testing, local.'],
                                    ['Host', 'Alamat LDAP server.'],
                                    ['Port', 'Port LDAP, biasanya 389 atau 636.'],
                                    ['Base DN', 'Root pencarian LDAP.'],
                                    ['Bind DN', 'Akun LDAP yang dipakai aplikasi untuk login.'],
                                    ['Bind Password', 'Password bind. Saat edit, kosongkan jika tidak ingin mengubah.'],
                                    ['SSL / TLS', 'Mengatur LDAPS atau StartTLS.'],
                                    ['Read-only Mode', 'Menandai koneksi agar tidak dipakai untuk operasi destructive.'],
                                    ['Default Connection', 'Menentukan koneksi utama aplikasi.'],
                                ] as [$field, $desc])
                                    <tr class="text-gray-300">
                                        <td class="px-4 py-3 font-medium text-white">{{ $field }}</td>
                                        <td class="px-4 py-3">{{ $desc }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="operations" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">4. Operations</h2>
                    <p class="mt-3 text-sm leading-7 text-gray-300">
                        Operations digunakan untuk pekerjaan administratif dan pekerjaan massal.
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        @foreach ([
                            ['LDIF Exports', 'Export data LDAP menjadi file LDIF.'],
                            ['LDAP Transfer Center', 'Memindahkan atau menyalin entry dari satu LDAP ke LDAP lain.'],
                            ['LDAP Sync Center', 'Sinkronisasi data LDAP ke cache aplikasi.'],
                            ['Operation Jobs', 'Pusat pelacakan pekerjaan administratif.'],
                            ['Queue Jobs', 'Memantau pekerjaan Laravel queue.'],
                            ['LDAP Bulk Operations', 'Menjalankan operasi LDAP massal berdasarkan base DN, custom DN, RDN, atau LDAP filter.'],
                            ['LDAP Import Center', 'Import data LDAP dari template, file, atau batch import.'],
                            ['Command Executions', 'Mencatat eksekusi command administratif dan hasil teknisnya.'],
                        ] as [$title, $desc])
                            <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <h3 class="font-semibold text-white">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-400">{{ $desc }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section id="bulk-operations" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">5. LDAP Bulk Operations</h2>
                    <p class="mt-3 text-sm leading-7 text-gray-300">
                        LDAP Bulk Operations digunakan untuk menjalankan operasi LDAP massal.
                    </p>

                    <div class="mt-5 rounded-xl border border-danger-500/20 bg-danger-500/10 p-4">
                        <div class="flex gap-3">
                            <x-heroicon-o-exclamation-triangle class="h-6 w-6 shrink-0 text-danger-400" />
                            <div>
                                <h3 class="font-semibold text-danger-300">Danger Zone</h3>
                                <p class="mt-1 text-sm leading-6 text-danger-100/80">
                                    Bulk delete, delete objectClass, move OU, dan delete attribute harus dipreview dulu.
                                    Jangan apply jika jumlah target tidak sesuai.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-3">
                        @foreach ([
                            ['1', 'Create Operation', 'Buat operasi baru dan isi LDAP Connection, Base DN, Filter, Search Scope, dan Operation Type.'],
                            ['2', 'Generate Preview', 'Preview membaca target LDAP dan membuat rencana perubahan tanpa mengubah LDAP asli.'],
                            ['3', 'Apply / Rollback', 'Apply menjalankan perubahan. Rollback membatalkan jika rollback payload tersedia.'],
                        ] as [$num, $title, $desc])
                            <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white">
                                        {{ $num }}
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-white">{{ $title }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-gray-400">{{ $desc }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 rounded-xl border border-white/10 bg-gray-950 p-4">
                        <div class="mb-2 text-sm font-semibold text-white">Contoh Filter Bulk Alumni</div>
                        <pre class="overflow-x-auto text-sm leading-6 text-gray-300"><code>Base DN:
ou=alumni,ou=people,dc=petra,dc=ac,dc=id

LDAP Filter:
(|(uid=usr000046)(uid=usr000047)(uid=usr000048))

Operation Type:
Add ObjectClass

ObjectClass:
domainRelatedObject

MUST Attribute:
associatedDomain = alumni.petra.ac.id</code></pre>
                    </div>
                </section>

                <section id="import-export" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">6. Import, Export, Transfer, Sync</h2>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <h3 class="font-semibold text-white">LDIF Exports</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-400">
                                Digunakan untuk backup atau export data LDAP menjadi LDIF.
                                Tentukan LDAP Connection, Base DN, Search Scope, Filter, Attributes, dan Size Limit.
                            </p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <h3 class="font-semibold text-white">LDAP Import Center</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-400">
                                Digunakan untuk import data LDAP dari template, file, atau batch.
                                Selalu generate plan sebelum apply.
                            </p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <h3 class="font-semibold text-white">LDAP Transfer Center</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-400">
                                Digunakan untuk copy atau transfer data antar LDAP.
                                Exclude operational attributes seperti entryUUID, entryCSN, createTimestamp, dan modifyTimestamp.
                            </p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                            <h3 class="font-semibold text-white">LDAP Sync Center</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-400">
                                Digunakan untuk refresh cache aplikasi setelah data LDAP berubah.
                            </p>
                        </div>
                    </div>
                </section>

                <section id="observability" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">7. Observability</h2>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        @foreach ([
                            ['Audit Logs', 'Mencatat siapa melakukan aksi, module, action, status, target DN, before value, dan after value.'],
                            ['Failed Jobs', 'Melihat queue job yang gagal beserta exception dan payload.'],
                            ['System Logs', 'Melihat error runtime Laravel, LDAP, database, Filament, dan Livewire.'],
                            ['Health Checks', 'Memantau kesehatan database, LDAP connection, queue worker, storage, log permission, dan Keycloak.'],
                            ['Operation Jobs', 'Melihat status job operasi, item per DN, log job, total OK, total failed, dan metadata.'],
                            ['Command Executions', 'Melihat command, stdout, stderr, preview command, dan execution result.'],
                        ] as [$title, $desc])
                            <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <h3 class="font-semibold text-white">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-400">{{ $desc }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section id="ldap-concepts" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">8. LDAP Concepts</h2>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        @foreach ([
                            ['Base DN', 'Titik awal pencarian LDAP. Contoh: ou=alumni,ou=people,dc=petra,dc=ac,dc=id'],
                            ['DN', 'Alamat lengkap sebuah entry. Contoh: uid=usr000046,ou=alumni,ou=people,dc=petra,dc=ac,dc=id'],
                            ['RDN', 'Bagian pertama dari DN. Contoh: uid=usr000046'],
                            ['LDAP Filter', 'Syarat pencarian LDAP. Contoh: (uid=usr000046)'],
                            ['Structural ObjectClass', 'Jenis utama entry LDAP. Umumnya tidak boleh sembarangan diganti.'],
                            ['Auxiliary ObjectClass', 'ObjectClass tambahan untuk memperluas attribute entry.'],
                        ] as [$title, $desc])
                            <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <h3 class="font-semibold text-white">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-400">{{ $desc }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 rounded-xl border border-white/10 bg-gray-950 p-4">
                        <div class="mb-2 text-sm font-semibold text-white">Contoh LDAP Filter</div>
                        <pre class="overflow-x-auto text-sm leading-6 text-gray-300"><code>(objectClass=*)

(objectClass=inetOrgPerson)

(uid=usr000046)

(|(uid=usr000046)(uid=usr000047)(uid=usr000048))

(&(objectClass=inetOrgPerson)(uid=usr000046))

(mail=*)</code></pre>
                    </div>
                </section>

                <section id="troubleshooting" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">9. Troubleshooting Umum</h2>

                    <div class="mt-5 space-y-4">
                        @foreach ([
                            ['LDAP Connection gagal', 'Cek host, port, firewall, bind DN, password, SSL/TLS, dan Base DN.'],
                            ['Preview tidak menemukan entry', 'Cek Base DN, LDAP Filter, Search Scope, dan coba filter (objectClass=*).'],
                            ['Apply berhasil tapi Apache Directory Studio belum berubah', 'Refresh entry, cek LDAP Connection yang dipakai, cek Operation Jobs, Audit Logs, dan Execution Result.'],
                            ['Add ObjectClass gagal', 'Pastikan objectClass adalah auxiliary dan semua MUST attribute sudah diisi.'],
                            ['Delete ObjectClass gagal', 'ObjectClass mungkin masih dibutuhkan atau attribute terkait masih ada. Cek Schema Browser.'],
                            ['Queue Job tidak jalan', 'Cek Queue Jobs, Failed Jobs, worker Laravel, dan queue:restart.'],
                            ['Error database', 'Cek System Logs, migration, model cast, column type, dan JSON payload.'],
                        ] as [$problem, $solution])
                            <div class="rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <div class="font-semibold text-white">{{ $problem }}</div>
                                <div class="mt-2 text-sm leading-6 text-gray-400">{{ $solution }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section id="sop" class="rounded-2xl border border-white/10 bg-gray-900/60 p-6">
                    <h2 class="text-xl font-bold text-white">10. SOP Harian Administrator</h2>

                    <div class="mt-5 grid gap-3">
                        @foreach ([
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
                        ] as $index => $step)
                            <div class="flex gap-3 rounded-xl border border-white/10 bg-gray-950/50 p-4">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white">
                                    {{ $index + 1 }}
                                </div>
                                <div class="pt-1 text-sm text-gray-300">{{ $step }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

            </div>
        </div>
    </div>
</x-filament-panels::page>
