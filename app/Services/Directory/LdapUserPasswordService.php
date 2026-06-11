<?php

namespace App\Services\Directory;

use App\Models\Directory\LdapConnection;
use App\Models\Directory\LdapUserEntry;
use App\Models\Operations\CommandExecution;
use Symfony\Component\Process\Process;
use Throwable;

class LdapUserPasswordService
{
    private string $ntAttribute = 'sambaNTPassword';

    private string $sambaObjectClass = 'sambaSamAccount';

    public function changePassword(LdapUserEntry $user, string $newPassword): array
    {
        $newPassword = (string) $newPassword;

        if (strlen($newPassword) < 12) {
            return $this->failed('Password minimal 12 karakter.');
        }

        $connection = LdapConnection::query()->find($user->ldap_connection_id);

        if (! $connection) {
            return $this->failed('LDAP connection not found.');
        }

        if (! filled($user->dn)) {
            return $this->failed('User DN is empty.');
        }

        $execution = CommandExecution::query()->create([
            'command_type' => 'ldap_user_change_password',
            'status' => 'running',
            'command' => 'ldapmodify -v -x -H '.$this->ldapUri($connection).' -D '.$this->bindDn($connection).' -w [REDACTED] -f [REDACTED_LDIF]',
            'environment_context' => [
                'ldap_connection_id' => $connection->id,
                'ldap_connection_name' => $connection->name ?? null,
                'target_user_id' => $user->id,
                'target_dn' => $user->dn,
                'operation' => 'change_user_password',
                'ldap_password_attribute' => 'userPassword',
                'ldap_password_hash' => '{SSHA}',
                'peap_nt_attribute' => $this->ntAttribute,
                'peap_object_class' => $this->sambaObjectClass,
                'samba_used' => true,
                'password_plain_logged' => false,
                'password_hash_logged' => false,
            ],
            'started_at' => now(),
        ]);

        $ldifFile = null;

        try {
            $ssha512 = $this->hashSsha($newPassword);
            $ntPassword = $this->hashNtPassword($newPassword);

            $schema = $this->detectSambaSchema($connection);
            $ntHashUpdated = false;
            $ntHashSkippedReason = null;

            $changes = [
                [
                    'operation' => 'replace',
                    'attribute' => 'userPassword',
                    'values' => [$ssha512],
                ],
            ];

            if ($schema['has_nt_attribute'] && $schema['has_samba_object_class']) {
                /*
                 * Semua user Petra sudah dimigrasikan ke sambaSamAccount + sambaSID.
                 * Jadi di sini kita cukup replace sambaNTPassword.
                 *
                 * LDAP modify "replace" akan:
                 * - mengganti nilai kalau atribut sudah ada
                 * - menambahkan atribut kalau belum ada, selama objectClass mengizinkan
                 */
                $changes[] = [
                    'operation' => 'replace',
                    'attribute' => $this->ntAttribute,
                    'values' => [$ntPassword],
                ];

                $ntHashUpdated = true;
            } else {
                $ntHashSkippedReason = 'Samba schema belum tersedia. userPassword tetap diganti, tetapi sambaNTPassword belum dapat di-update untuk PEAP/MSCHAPv2.';
            }

            $tmpDir = storage_path('app/private/ldap-mutations/passwords');

            if (! is_dir($tmpDir)) {
                @mkdir($tmpDir, 0775, true);
            }

            $ldifFile = $tmpDir.'/'.date('Ymd_His').'_change_password_'.$user->id.'_'.bin2hex(random_bytes(4)).'.ldif';
            $ldif = $this->buildModifyLdif($user->dn, $changes);

            file_put_contents($ldifFile, $ldif);
            @chmod($ldifFile, 0600);

            $process = new Process([
                'ldapmodify',
                '-v',
                '-x',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                '-f',
                $ldifFile,
            ], base_path());

            $process->setTimeout(120);
            $process->run();

            $stdout = $this->redactSensitiveText($process->getOutput());
            $stderr = $this->redactSensitiveText($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                $message = trim($stderr ?: $stdout ?: 'ldapmodify password failed.');

                $execution->forceFill([
                    'status' => 'failed',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => $process->getExitCode(),
                    'error_message' => $message,
                    'finished_at' => now(),
                ])->save();

                return $this->failed($message, $execution->id);
            }

            try {
                $fresh = $user->fresh();

                if ($fresh) {
                    app(LdapSingleUserSyncService::class)->sync($fresh);
                }
            } catch (Throwable $refreshError) {
                report($refreshError);
            }

            $execution->forceFill([
                'status' => 'success',
                'stdout' => 'LDAP password changed successfully. Sensitive values redacted.',
                'stderr' => $stderr,
                'exit_code' => 0,
                'error_message' => null,
                'environment_context' => [
                    'ldap_connection_id' => $connection->id,
                    'ldap_connection_name' => $connection->name ?? null,
                    'target_user_id' => $user->id,
                    'target_dn' => $user->dn,
                    'operation' => 'change_user_password',
                    'ldap_password_attribute' => 'userPassword',
                    'ldap_password_hash' => '{SSHA}',
                    'peap_nt_attribute' => $this->ntAttribute,
                    'peap_object_class' => $this->sambaObjectClass,
                    'nt_hash_updated' => $ntHashUpdated,
                    'nt_hash_skipped_reason' => $ntHashSkippedReason,
                    'samba_used' => true,
                    'password_plain_logged' => false,
                    'password_hash_logged' => false,
                    'ldap_user_entries' => 'refreshed',
                    'ldap_directory_entries' => 'refreshed',
                ],
                'finished_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'message' => $ntHashUpdated
                    ? 'Password changed. userPassword={SSHA}, sambaNTPassword=NT-Password.'
                    : 'Password changed. userPassword={SSHA}. '.$ntHashSkippedReason,
                'command_execution_id' => $execution->id,
                'ldap_password_hash' => '{SSHA}',
                'peap_nt_attribute' => $this->ntAttribute,
                'nt_hash_updated' => $ntHashUpdated,
                'nt_hash_skipped_reason' => $ntHashSkippedReason,
            ];
        } catch (Throwable $e) {
            $execution->forceFill([
                'status' => 'failed',
                'stdout' => null,
                'stderr' => $this->redactSensitiveText($e->getMessage()),
                'exit_code' => 1,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            return $this->failed($e->getMessage(), $execution->id);
        } finally {
            if ($ldifFile && file_exists($ldifFile)) {
                @unlink($ldifFile);
            }
        }
    }

    private function buildModifyLdif(string $dn, array $changes): string
    {
        $lines = [
            'dn: '.$dn,
            'changetype: modify',
        ];

        foreach ($changes as $index => $change) {
            if ($index > 0) {
                $lines[] = '-';
            }

            $lines[] = $change['operation'].': '.$change['attribute'];

            foreach (($change['values'] ?? []) as $value) {
                $lines[] = $change['attribute'].': '.$value;
            }
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function hashSsha(string $plainPassword): string
    {
        $salt = random_bytes(8);
        $digest = sha1($plainPassword.$salt, true);

        return '{SSHA}'.base64_encode($digest.$salt);
    }

    private function hashNtPassword(string $plainPassword): string
    {
        $utf16le = mb_convert_encoding($plainPassword, 'UTF-16LE', 'UTF-8');

        if (in_array('md4', hash_algos(), true)) {
            return strtoupper(hash('md4', $utf16le));
        }

        return strtoupper(bin2hex($this->md4($utf16le)));
    }

    private function detectSambaSchema(LdapConnection $connection): array
    {
        $result = [
            'has_nt_attribute' => false,
            'has_samba_object_class' => false,
        ];

        try {
            $process = new Process([
                'ldapsearch',
                '-LLL',
                '-x',
                '-o',
                'ldif-wrap=no',
                '-H',
                $this->ldapUri($connection),
                '-D',
                $this->bindDn($connection),
                '-w',
                $this->bindPassword($connection),
                '-b',
                'cn=subschema',
                '-s',
                'base',
                '(objectClass=*)',
                'attributeTypes',
                'objectClasses',
            ], base_path());

            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                return $result;
            }

            $output = strtolower($process->getOutput());

            $result['has_nt_attribute'] = str_contains($output, strtolower($this->ntAttribute));
            $result['has_samba_object_class'] = str_contains($output, strtolower($this->sambaObjectClass));

            return $result;
        } catch (Throwable) {
            return $result;
        }
    }

    private function ldapUri(LdapConnection $connection): string
    {
        $host = $connection->host ?? '127.0.0.1';
        $port = $connection->port ?? 389;

        if (str_starts_with((string) $host, 'ldap://') || str_starts_with((string) $host, 'ldaps://')) {
            return (string) $host;
        }

        return 'ldap://'.$host.':'.$port;
    }

    private function bindDn(LdapConnection $connection): string
    {
        return (string) ($connection->bind_dn ?? '');
    }

    private function bindPassword(LdapConnection $connection): string
    {
        return (string) ($connection->bind_password ?? '');
    }

    private function failed(string $message, ?int $commandExecutionId = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'command_execution_id' => $commandExecutionId,
        ];
    }

    private function redactSensitiveText(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            '/(userPassword:\s*)(.+)/i',
            '/(petraNTPassword:\s*)(.+)/i',
            '/(sambaNTPassword:\s*)(.+)/i',
            '/(\{SSHA\})([A-Za-z0-9+\/=]+)/i',
            '/(\{SSHA\})([A-Za-z0-9+\/=]+)/i',
            '/(-w\s+)([^\s]+)/i',
            '/(password\s*[=:]\s*)([^\s]+)/i',
            '/(bind_password\s*[=:]\s*)([^\s]+)/i',
            '/([A-Fa-f0-9]{32})/',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    /**
     * Pure PHP MD4 fallback.
     * NT hash = MD4(UTF-16LE(password)).
     */
    private function md4(string $message): string
    {
        $bytes = array_values(unpack('C*', $message));
        $bitLength = count($bytes) * 8;

        $bytes[] = 0x80;

        while ((count($bytes) % 64) !== 56) {
            $bytes[] = 0x00;
        }

        for ($i = 0; $i < 8; $i++) {
            $bytes[] = ($bitLength >> ($i * 8)) & 0xff;
        }

        $a = 0x67452301;
        $b = 0xefcdab89;
        $c = 0x98badcfe;
        $d = 0x10325476;

        for ($offset = 0; $offset < count($bytes); $offset += 64) {
            $x = [];

            for ($i = 0; $i < 16; $i++) {
                $j = $offset + ($i * 4);
                $x[$i] = $bytes[$j]
                    | ($bytes[$j + 1] << 8)
                    | ($bytes[$j + 2] << 16)
                    | ($bytes[$j + 3] << 24);
                $x[$i] &= 0xffffffff;
            }

            $aa = $a;
            $bb = $b;
            $cc = $c;
            $dd = $d;

            $s1 = [3, 7, 11, 19];

            for ($i = 0; $i < 16; $i++) {
                [$a, $b, $c, $d] = $this->md4Round1($a, $b, $c, $d, $x[$i], $s1[$i % 4]);
            }

            $s2 = [3, 5, 9, 13];
            $order2 = [0, 4, 8, 12, 1, 5, 9, 13, 2, 6, 10, 14, 3, 7, 11, 15];

            for ($i = 0; $i < 16; $i++) {
                [$a, $b, $c, $d] = $this->md4Round2($a, $b, $c, $d, $x[$order2[$i]], $s2[$i % 4]);
            }

            $s3 = [3, 9, 11, 15];
            $order3 = [0, 8, 4, 12, 2, 10, 6, 14, 1, 9, 5, 13, 3, 11, 7, 15];

            for ($i = 0; $i < 16; $i++) {
                [$a, $b, $c, $d] = $this->md4Round3($a, $b, $c, $d, $x[$order3[$i]], $s3[$i % 4]);
            }

            $a = $this->u32($a + $aa);
            $b = $this->u32($b + $bb);
            $c = $this->u32($c + $cc);
            $d = $this->u32($d + $dd);
        }

        return pack('V4', $a, $b, $c, $d);
    }

    private function md4Round1(int $a, int $b, int $c, int $d, int $x, int $s): array
    {
        $a = $this->rol($this->u32($a + $this->md4F($b, $c, $d) + $x), $s);

        return [$d, $a, $b, $c];
    }

    private function md4Round2(int $a, int $b, int $c, int $d, int $x, int $s): array
    {
        $a = $this->rol($this->u32($a + $this->md4G($b, $c, $d) + $x + 0x5a827999), $s);

        return [$d, $a, $b, $c];
    }

    private function md4Round3(int $a, int $b, int $c, int $d, int $x, int $s): array
    {
        $a = $this->rol($this->u32($a + $this->md4H($b, $c, $d) + $x + 0x6ed9eba1), $s);

        return [$d, $a, $b, $c];
    }

    private function md4F(int $x, int $y, int $z): int
    {
        return $this->u32(($x & $y) | ((~$x) & $z));
    }

    private function md4G(int $x, int $y, int $z): int
    {
        return $this->u32(($x & $y) | ($x & $z) | ($y & $z));
    }

    private function md4H(int $x, int $y, int $z): int
    {
        return $this->u32($x ^ $y ^ $z);
    }

    private function rol(int $value, int $bits): int
    {
        $value = $this->u32($value);

        return $this->u32(($value << $bits) | ($value >> (32 - $bits)));
    }

    private function u32(int $value): int
    {
        return $value & 0xffffffff;
    }
}
