<?php

namespace App\Filament\Resources\Radius;

use App\Filament\Resources\Radius\RadiusUserReadinessResource\Pages;
use App\Models\Directory\LdapUserEntry;
use App\Models\Directory\LdapGroupEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use BackedEnum;
use UnitEnum;

class RadiusUserReadinessResource extends Resource
{
    protected static ?string $model = LdapUserEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wifi';

    protected static string|UnitEnum|null $navigationGroup = '4. MISCELLANEOUS';

    protected static ?string $navigationLabel = 'WiFi Readiness';

    protected static ?string $modelLabel = 'WiFi Readiness User';

    protected static ?string $pluralModelLabel = 'WiFi Readiness';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('1. User Identity')
                    ->schema([
                        TextEntry::make('uid')
                            ->label('UID')
                            ->state(fn (LdapUserEntry $record): string => self::firstAttr($record, 'uid') ?: (string) ($record->uid ?? '-')),

                        TextEntry::make('cn')
                            ->label('Name')
                            ->state(fn (LdapUserEntry $record): string => self::firstAttr($record, 'cn') ?: (string) ($record->cn ?? '-')),

                        TextEntry::make('mail')
                            ->label('Mail')
                            ->state(fn (LdapUserEntry $record): string => self::firstAttr($record, 'mail') ?: (string) ($record->mail ?? '-')),

                        TextEntry::make('dn')
                            ->label('DN')
                            ->columnSpanFull(),

                        TextEntry::make('ldap_connection_id')
                            ->label('LDAP Connection ID')
                            ->badge(),

                        TextEntry::make('last_synced_at')
                            ->label('Last Synced')
                            ->dateTime()
                            ->placeholder('Never'),
                    ])
                    ->columns(2),

                Section::make('2. Samba / MSCHAPv2 Readiness')
                    ->schema([
                        TextEntry::make('wifi_status')
                            ->label('Final Status')
                            ->badge()
                            ->state(fn (LdapUserEntry $record): string => self::readinessStatus($record))
                            ->color(fn (string $state): string => self::statusColor($state)),

                        TextEntry::make('has_samba_account')
                            ->label('sambaSamAccount')
                            ->badge()
                            ->state(fn (LdapUserEntry $record): string => self::hasObjectClass($record, 'sambaSamAccount') ? 'YES' : 'NO')
                            ->color(fn (string $state): string => $state === 'YES' ? 'success' : 'danger'),

                        TextEntry::make('samba_sid')
                            ->label('sambaSID')
                            ->state(fn (LdapUserEntry $record): string => self::firstAttr($record, 'sambaSID') ?: 'missing')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'missing' ? 'danger' : 'success'),

                        TextEntry::make('samba_acct_flags')
                            ->label('sambaAcctFlags')
                            ->state(fn (LdapUserEntry $record): string => self::firstAttr($record, 'sambaAcctFlags') ?: 'missing')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'missing' ? 'danger' : 'success'),

                        TextEntry::make('samba_nt_password')
                            ->label('sambaNTPassword')
                            ->state(fn (LdapUserEntry $record): string => self::hasAttr($record, 'sambaNTPassword') ? '[REDACTED / EXISTS]' : 'missing')
                            ->badge()
                            ->color(fn (string $state): string => str_contains($state, 'EXISTS') ? 'success' : 'warning'),

                        TextEntry::make('user_password')
                            ->label('userPassword')
                            ->state(fn (LdapUserEntry $record): string => self::hasAttr($record, 'userPassword') ? '[REDACTED / EXISTS]' : 'missing')
                            ->badge()
                            ->color(fn (string $state): string => str_contains($state, 'EXISTS') ? 'success' : 'warning'),
                    ])
                    ->columns(2),

                Section::make('3. WiFi / RADIUS Policy')
                    ->schema([
                        TextEntry::make('wifi_group')
                            ->label('Member app-wifi-dot1x')
                            ->badge()
                            ->state(fn (LdapUserEntry $record): string => self::inWifiGroup($record) ? 'YES' : 'NO')
                            ->color(fn (string $state): string => $state === 'YES' ? 'success' : 'info'),

                        TextEntry::make('petra_vlan')
                            ->label('petraVlan')
                            ->badge()
                            ->state(function (LdapUserEntry $record): string {
                                $values = self::attrValues($record, 'petraVlan');

                                return $values === [] ? 'none' : implode(', ', $values);
                            })
                            ->color(fn (string $state): string => $state === 'none' ? 'danger' : 'success'),

                        TextEntry::make('next_action')
                            ->label('Next Required Action')
                            ->columnSpanFull()
                            ->state(function (LdapUserEntry $record): string {
                                return match (self::readinessStatus($record)) {
                                    'READY' => 'User is ready for WiFi / RADIUS / 802.1X.',
                                    'NEED SAMBA REPAIR' => 'Repair Samba attributes: sambaSamAccount, sambaSID, and sambaAcctFlags.',
                                    'NEED WIFI GROUP' => 'Add user DN into cn=app-wifi-dot1x,ou=apps,ou=groups,dc=petra,dc=ac,dc=id.',
                                    'NEED VLAN' => 'Assign petraVlan for RADIUS VLAN reply.',
                                    'NEED PASSWORD SYNC' => 'Change/reset password from dashboard so userPassword and sambaNTPassword are generated together.',
                                    default => 'Review LDAP attributes.',
                                };
                            }),
                    ])
                    ->columns(2),

                Section::make('4. Raw Safe Attribute Summary')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('object_classes_display')
                            ->label('objectClass')
                            ->columnSpanFull()
                            ->state(fn (LdapUserEntry $record): string => implode(', ', self::attrValues($record, 'objectClass')) ?: '-'),

                        TextEntry::make('group_dns_display')
                            ->label('group_dns')
                            ->columnSpanFull()
                            ->state(function (LdapUserEntry $record): string {
                                $value = $record->group_dns ?? [];

                                if (is_string($value)) {
                                    $decoded = json_decode($value, true);
                                    $value = is_array($decoded) ? $decoded : [];
                                }

                                return is_array($value) && $value !== []
                                    ? implode("\n", array_map('strval', $value))
                                    : '-';
                            }),

                        TextEntry::make('attributes_safe_summary')
                            ->label('Attributes Summary')
                            ->columnSpanFull()
                            ->state(function (LdapUserEntry $record): string {
                                $keys = [
                                    'uid',
                                    'cn',
                                    'mail',
                                    'petraAccountStatus',
                                    'petraAffiliation',
                                    'petraVlan',
                                    'sambaSID',
                                    'sambaAcctFlags',
                                    'sambaNTPassword',
                                    'userPassword',
                                ];

                                $lines = [];

                                foreach ($keys as $key) {
                                    if (in_array($key, ['sambaNTPassword', 'userPassword'], true)) {
                                        $lines[] = $key . ': ' . (self::hasAttr($record, $key) ? '[REDACTED / EXISTS]' : 'missing');
                                        continue;
                                    }

                                    $values = self::attrValues($record, $key);
                                    $lines[] = $key . ': ' . ($values === [] ? 'missing' : implode(', ', $values));
                                }

                                return implode("\n", $lines);
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('uid')
                    ->label('UID')
                    ->searchable()
                    ->sortable()
                    ->getStateUsing(fn (LdapUserEntry $record): string => self::firstAttr($record, 'uid') ?: (string) ($record->uid ?? '-')),

                TextColumn::make('cn')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->getStateUsing(fn (LdapUserEntry $record): string => self::firstAttr($record, 'cn') ?: (string) ($record->cn ?? '-')),

                TextColumn::make('mail')
                    ->label('Mail')
                    ->searchable()
                    ->toggleable()
                    ->getStateUsing(fn (LdapUserEntry $record): string => self::firstAttr($record, 'mail') ?: (string) ($record->mail ?? '-')),

                IconColumn::make('has_samba_account')
                    ->label('Samba')
                    ->boolean()
                    ->getStateUsing(fn (LdapUserEntry $record): bool => self::hasObjectClass($record, 'sambaSamAccount')),

                IconColumn::make('has_samba_sid')
                    ->label('SID')
                    ->boolean()
                    ->getStateUsing(fn (LdapUserEntry $record): bool => self::hasAttr($record, 'sambaSID')),

                IconColumn::make('has_samba_nt_password')
                    ->label('NT Hash')
                    ->boolean()
                    ->getStateUsing(fn (LdapUserEntry $record): bool => self::hasAttr($record, 'sambaNTPassword')),

                IconColumn::make('has_user_password')
                    ->label('LDAP Pass')
                    ->boolean()
                    ->getStateUsing(fn (LdapUserEntry $record): bool => self::hasAttr($record, 'userPassword')),

                TextColumn::make('petra_vlan')
                    ->label('VLAN')
                    ->badge()
                    ->getStateUsing(function (LdapUserEntry $record): string {
                        $values = self::attrValues($record, 'petraVlan');

                        return $values === [] ? 'none' : implode(', ', $values);
                    }),

                TextColumn::make('radius_status')
                    ->label('802.1X Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'READY' => 'success',
                        'NEED PASSWORD SYNC' => 'warning',
                        'NEED WIFI GROUP' => 'info',
                        'NEED VLAN' => 'danger',
                        'NEED SAMBA REPAIR' => 'danger',
                        default => 'gray',
                    })
                    ->getStateUsing(fn (LdapUserEntry $record): string => self::readinessStatus($record)),

                TextColumn::make('last_synced_at')
                    ->label('Last Sync')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('radius_readiness')
                    ->label('Readiness')
                    ->options([
                        'ready' => 'READY',
                        'need_password_sync' => 'NEED PASSWORD SYNC',
                        'need_wifi_group' => 'NEED WIFI GROUP',
                        'need_vlan' => 'NEED VLAN',
                        'need_samba_repair' => 'NEED SAMBA REPAIR',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'ready' => $query
                                ->where('attributes', 'like', '%sambaSamAccount%')
                                ->where('attributes', 'like', '%sambaSID%')
                                ->where('attributes', 'like', '%sambaNTPassword%')
                                ->where('attributes', 'like', '%userPassword%')
                                ->where('attributes', 'like', '%petraVlan%'),

                            'need_password_sync' => $query
                                ->where(function (Builder $q): void {
                                    $q->where('attributes', 'not like', '%sambaNTPassword%')
                                        ->orWhere('attributes', 'not like', '%userPassword%');
                                }),

                            'need_wifi_group' => $query
                                ->whereNotIn('dn', array_keys(self::wifiMemberDns())),

                            'need_vlan' => $query
                                ->where('attributes', 'not like', '%petraVlan%'),

                            'need_samba_repair' => $query
                                ->where(function (Builder $q): void {
                                    $q->where('attributes', 'not like', '%sambaSamAccount%')
                                        ->orWhere('attributes', 'not like', '%sambaSID%')
                                        ->orWhere('attributes', 'not like', '%sambaAcctFlags%');
                                }),

                            default => $query,
                        };
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('ldap_connection_id', 2)
            ->where('dn', 'like', '%ou=people,dc=petra,dc=ac,dc=id')
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', [
                        'missing_from_ldap',
                        'deleted_from_ldap',
                        'missing',
                        'deleted',
                    ]);
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRadiusUserReadiness::route('/'),
            'view' => Pages\ViewRadiusUserReadiness::route('/{record}'),
        ];
    }

    protected static function statusColor(string $state): string
    {
        return match ($state) {
            'READY' => 'success',
            'NEED PASSWORD SYNC' => 'warning',
            'NEED WIFI GROUP' => 'info',
            'NEED VLAN' => 'danger',
            'NEED SAMBA REPAIR' => 'danger',
            default => 'gray',
        };
    }

    protected static function readinessStatus(LdapUserEntry $record): string
    {
        if (
            ! self::hasObjectClass($record, 'sambaSamAccount')
            || ! self::hasAttr($record, 'sambaSID')
            || ! self::hasAttr($record, 'sambaAcctFlags')
        ) {
            return 'NEED SAMBA REPAIR';
        }

        if (! self::inWifiGroup($record)) {
            return 'NEED WIFI GROUP';
        }

        if (! self::hasAttr($record, 'petraVlan')) {
            return 'NEED VLAN';
        }

        if (! self::hasAttr($record, 'userPassword') || ! self::hasAttr($record, 'sambaNTPassword')) {
            return 'NEED PASSWORD SYNC';
        }

        return 'READY';
    }

    protected static function inWifiGroup(LdapUserEntry $record): bool
    {
        $dn = strtolower(trim((string) $record->dn));

        return $dn !== '' && isset(self::wifiMemberDns()[$dn]);
    }

    protected static function wifiMemberDns(): array
    {
        static $members = null;

        if (is_array($members)) {
            return $members;
        }

        $members = [];

        $group = LdapGroupEntry::query()
            ->where(function (Builder $query): void {
                $query->where('cn', 'app-wifi-dot1x')
                    ->orWhere('dn', 'like', 'cn=app-wifi-dot1x,%')
                    ->orWhere('attributes', 'like', '%app-wifi-dot1x%');
            })
            ->orderByDesc('updated_at')
            ->first();

        if (! $group) {
            return $members;
        }

        foreach (self::attrValues($group, 'member') as $memberDn) {
            $normalized = strtolower(trim((string) $memberDn));

            if ($normalized !== '') {
                $members[$normalized] = true;
            }
        }

        return $members;
    }

    protected static function hasObjectClass(LdapUserEntry $record, string $class): bool
    {
        foreach (self::attrValues($record, 'objectClass') as $value) {
            if (strcasecmp($value, $class) === 0) {
                return true;
            }
        }

        return false;
    }

    protected static function hasAttr(LdapUserEntry $record, string $name): bool
    {
        return self::attrValues($record, $name) !== [];
    }

    protected static function firstAttr(LdapUserEntry $record, string $name): ?string
    {
        $values = self::attrValues($record, $name);

        return $values[0] ?? null;
    }

    protected static function attrValues($record, string $name): array
    {
        $attributes = $record->attributes ?? [];

        if (is_string($attributes)) {
            $decoded = json_decode($attributes, true);
            $attributes = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($attributes)) {
            return [];
        }

        $value = null;

        foreach ($attributes as $key => $candidate) {
            if (strcasecmp((string) $key, $name) === 0) {
                $value = $candidate;
                break;
            }
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $value
            ), static fn (string $item): bool => $item !== ''));
        }

        return [trim((string) $value)];
    }
}
