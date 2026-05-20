<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CheckLoggingCoverage extends Command
{
    protected $signature = 'iam:logging-coverage-check
        {--details : Show matched files and missing hints}
        {--module= : Filter by module keyword}';

    protected $description = 'Check logging coverage across LDAP dashboard modules.';

    private array $loggingSignals = [
        'UnifiedActivityLogger',
        'AuditLog',
        'audit_logs',
        'operation_jobs',
        'operation_job_logs',
        'CommandExecution',
        'command_executions',
        'SystemLog',
        'activity(',
        'logger()->',
        'Log::',
        'report(',
    ];

    private array $modules = [
        'LDAP Import Center' => [
            'keywords' => [
                'ImportBatch',
                'ImportTemplate',
                'LdapImport',
                'import_batches',
                'import_rows',
            ],
            'paths' => [
                'app/Filament/Resources/Operations',
                'app/Services/Operations',
                'app/Console/Commands',
            ],
        ],

        'LDAP Transfer Center' => [
            'keywords' => [
                'LdapTransfer',
                'TransferBatch',
                'TransferCenter',
                'transfer',
            ],
            'paths' => [
                'app/Filament/Resources/Operations',
                'app/Services/Operations',
                'app/Jobs/Operations',
                'app/Console/Commands',
            ],
        ],

        'LDAP Sync Center' => [
            'keywords' => [
                'LdapSync',
                'UniversalLdapSync',
                'sync_ldap',
                'ExecuteUniversalLdapSyncJob',
                'SchemaBrowserSync',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Jobs',
                'app/Console/Commands',
            ],
        ],

        'LDIF Export' => [
            'keywords' => [
                'LdifExport',
                'LDIF',
                'execute_ldif_export',
                'ldif_export',
            ],
            'paths' => [
                'app/Filament/Resources/Operations',
                'app/Services/Operations',
                'app/Jobs/Operations',
                'app/Console/Commands',
            ],
        ],

        'LDAP Connections' => [
            'keywords' => [
                'LdapConnection',
                'test_connection',
                'ldap_connections',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
            ],
        ],

        'LDAP Servers' => [
            'keywords' => [
                'LdapServer',
                'ldap_servers',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
            ],
        ],

        'Users' => [
            'keywords' => [
                'LdapUser',
                'UserResource',
                'DirectoryUser',
                'users',
                'inetOrgPerson',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
            ],
        ],

        'Directory Object Manager' => [
            'keywords' => [
                'DirectoryObject',
                'DirectoryObjectManager',
                'GenericDirectory',
                'LdapObject',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
            ],
        ],

        'Schema Browser' => [
            'keywords' => [
                'SchemaBrowser',
                'LdapSchema',
                'objectClass',
                'attributeType',
                'cn=subschema',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
                'app/Console/Commands',
            ],
        ],

        'Queue Jobs' => [
            'keywords' => [
                'QueueJob',
                'queue_jobs',
                'RefreshRedisQueue',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Jobs',
            ],
        ],

        'Failed Jobs' => [
            'keywords' => [
                'FailedJob',
                'failed_jobs',
                'retry',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
            ],
        ],

        'Health Checks' => [
            'keywords' => [
                'HealthCheck',
                'health_checks',
                'RunHealthChecks',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Console/Commands',
            ],
        ],

        'System Logs' => [
            'keywords' => [
                'SystemLog',
                'laravel.log',
                'LogReader',
                'storage/logs',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
            ],
        ],

        'Audit Logs' => [
            'keywords' => [
                'AuditLog',
                'audit_logs',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
            ],
        ],

        'Command Executions' => [
            'keywords' => [
                'CommandExecution',
                'command_executions',
                'RunSafeCommand',
                'ldapsearch',
                'ldapmodify',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Models',
            ],
        ],

        'Keycloak' => [
            'keywords' => [
                'Keycloak',
                'KeycloakApi',
                'keycloak',
                'OIDC',
                'SAML',
            ],
            'paths' => [
                'app/Filament/Resources',
                'app/Services',
                'app/Console/Commands',
            ],
        ],

        'Dashboard' => [
            'keywords' => [
                'Dashboard',
                'Widget',
                'StatsOverview',
            ],
            'paths' => [
                'app/Filament',
                'app/Services',
            ],
        ],

        'User Manual' => [
            'keywords' => [
                'UserManual',
                'Manual',
                'documentation',
            ],
            'paths' => [
                'app/Filament',
                'app/Services',
            ],
        ],
    ];

    public function handle(): int
    {
        $filter = trim((string) $this->option('module'));
        $details = (bool) $this->option('details');

        $this->info('LDAP Dashboard Logging Coverage Check');
        $this->line('Generated at: '.now()->toDateTimeString());
        $this->newLine();

        $rows = [];

        foreach ($this->modules as $module => $config) {
            if ($filter !== '' && stripos($module, $filter) === false) {
                continue;
            }

            $scan = $this->scanModule($module, $config);

            $rows[] = [
                'Module' => $module,
                'Status' => $scan['status'],
                'Files' => $scan['file_count'],
                'Logged Files' => $scan['logged_file_count'],
                'Signals' => $scan['signal_count'],
                'Recommendation' => $scan['recommendation'],
            ];

            if ($details) {
                $this->printDetails($module, $scan);
            }
        }

        $this->table(
            ['Module', 'Status', 'Files', 'Logged Files', 'Signals', 'Recommendation'],
            $rows
        );

        $summary = $this->summary($rows);

        $this->newLine();
        $this->info('Summary');
        $this->line('OK      : '.$summary['OK']);
        $this->line('PARTIAL : '.$summary['PARTIAL']);
        $this->line('MISSING : '.$summary['MISSING']);
        $this->line('UNKNOWN : '.$summary['UNKNOWN']);

        $this->newLine();
        $this->warn('Note: This command detects logging references statically. It does not guarantee runtime coverage.');
        $this->warn('Next step: patch modules marked MISSING/PARTIAL with UnifiedActivityLogger.');

        return self::SUCCESS;
    }

    private function scanModule(string $module, array $config): array
    {
        $files = $this->findRelevantFiles($config['paths'], $config['keywords']);

        $loggedFiles = [];
        $signals = [];

        foreach ($files as $file) {
            $content = File::get($file);

            foreach ($this->loggingSignals as $signal) {
                if (str_contains($content, $signal)) {
                    $loggedFiles[$file] = true;
                    $signals[] = [
                        'file' => $file,
                        'signal' => $signal,
                    ];
                }
            }
        }

        $fileCount = count($files);
        $loggedFileCount = count($loggedFiles);
        $signalCount = count($signals);

        $status = $this->status($fileCount, $loggedFileCount, $signalCount);

        return [
            'module' => $module,
            'status' => $status,
            'files' => $files,
            'logged_files' => array_keys($loggedFiles),
            'signals' => $signals,
            'file_count' => $fileCount,
            'logged_file_count' => $loggedFileCount,
            'signal_count' => $signalCount,
            'recommendation' => $this->recommendation($status),
        ];
    }

    private function findRelevantFiles(array $paths, array $keywords): array
    {
        $result = [];

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            $files = File::allFiles($path);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $filePath = $file->getPathname();

                // Do not count this checker as logging coverage evidence.
                // This file contains all logging keywords by design, so including it makes results too optimistic.
                if (str_ends_with($filePath, 'app/Console/Commands/CheckLoggingCoverage.php')) {
                    continue;
                }

                $content = File::get($filePath);
                $haystack = $filePath."\n".$content;

                foreach ($keywords as $keyword) {
                    if (stripos($haystack, $keyword) !== false) {
                        $result[$filePath] = true;
                        break;
                    }
                }
            }
        }

        ksort($result);

        return array_keys($result);
    }

    private function status(int $fileCount, int $loggedFileCount, int $signalCount): string
    {
        if ($fileCount === 0) {
            return 'UNKNOWN';
        }

        if ($signalCount === 0 || $loggedFileCount === 0) {
            return 'MISSING';
        }

        $ratio = $loggedFileCount / max($fileCount, 1);

        if ($ratio >= 0.5 || $signalCount >= 5) {
            return 'OK';
        }

        return 'PARTIAL';
    }

    private function recommendation(string $status): string
    {
        return match ($status) {
            'OK' => 'Keep. Verify runtime only.',
            'PARTIAL' => 'Add UnifiedActivityLogger to important actions.',
            'MISSING' => 'Add audit/operation logging.',
            default => 'Manual review required.',
        };
    }

    private function summary(array $rows): array
    {
        $summary = [
            'OK' => 0,
            'PARTIAL' => 0,
            'MISSING' => 0,
            'UNKNOWN' => 0,
        ];

        foreach ($rows as $row) {
            $status = $row['Status'];

            if (! array_key_exists($status, $summary)) {
                $summary['UNKNOWN']++;
                continue;
            }

            $summary[$status]++;
        }

        return $summary;
    }

    private function printDetails(string $module, array $scan): void
    {
        $this->newLine();
        $this->line('============================================================');
        $this->info($module);
        $this->line('Status: '.$scan['status']);
        $this->line('Files found: '.$scan['file_count']);
        $this->line('Logged files: '.$scan['logged_file_count']);
        $this->line('Signals: '.$scan['signal_count']);

        $this->line('Relevant files:');

        foreach (array_slice($scan['files'], 0, 30) as $file) {
            $this->line(' - '.$file);
        }

        if (count($scan['files']) > 30) {
            $this->line(' - ... '.(count($scan['files']) - 30).' more');
        }

        $this->line('Logging signals:');

        foreach (array_slice($scan['signals'], 0, 30) as $signal) {
            $this->line(' - '.$signal['signal'].' in '.$signal['file']);
        }

        if (count($scan['signals']) > 30) {
            $this->line(' - ... '.(count($scan['signals']) - 30).' more');
        }
    }
}
