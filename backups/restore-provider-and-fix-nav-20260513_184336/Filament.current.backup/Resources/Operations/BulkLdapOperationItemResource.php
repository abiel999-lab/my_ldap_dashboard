<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\BulkLdapOperationItemResource\Pages;
use App\Models\Operations\BulkLdapOperation;
use App\Models\Operations\BulkLdapOperationItem;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class BulkLdapOperationItemResource extends Resource
{
    protected static ?string $model = BulkLdapOperationItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    
    protected static bool $shouldRegisterNavigation = false;
protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Bulk LDAP Items';

    protected static ?string $modelLabel = 'Bulk LDAP Item';

    protected static ?string $pluralModelLabel = 'Bulk LDAP Items';

    protected static ?int $navigationSort = 47;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('bulk_ldap_operation_id')->label('Bulk Operation ID'),
                        TextEntry::make('sequence')->label('Sequence'),
                        TextEntry::make('uid')->label('UID'),
                        TextEntry::make('action')->label('Action')->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'pending' => 'gray',
                                'running' => 'warning',
                                'success', 'already_applied' => 'success',
                                'failed' => 'danger',
                                'conflict' => 'warning',
                                'skipped' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('target_dn')->label('Target DN')->columnSpanFull(),
                        TextEntry::make('destination_dn')->label('Destination DN')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Execution')
                    ->schema([
                        TextEntry::make('attempt_count')->label('Attempt Count'),
                        TextEntry::make('exit_code')->label('Exit Code')->placeholder('N/A'),
                        TextEntry::make('command_execution_id')->label('Command Execution ID')->placeholder('N/A'),
                        TextEntry::make('error_message')->label('Error')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('stdout')->label('STDOUT')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('stderr')->label('STDERR')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Preview')
                    ->schema([
                        TextEntry::make('ldif_preview')->label('LDIF Preview')->columnSpanFull(),
                    ]),

                Section::make('Raw Data')
                    ->schema([
                        TextEntry::make('payload_hash')->label('Payload Hash')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Created')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated')->dateTime(),
                        TextEntry::make('started_at')->label('Started')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('bulk_ldap_operation_id')
                    ->label('Bulk ID')
                    ->sortable(),

                TextColumn::make('sequence')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('uid')
                    ->label('UID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'gray',
                        'running' => 'warning',
                        'success', 'already_applied' => 'success',
                        'failed' => 'danger',
                        'conflict' => 'warning',
                        'skipped' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->searchable()
                    ->limit(70),

                TextColumn::make('attempt_count')
                    ->label('Try')
                    ->sortable(),

                TextColumn::make('exit_code')
                    ->label('Exit')
                    ->placeholder('N/A')
                    ->sortable(),

                TextColumn::make('error_message')
                    ->label('Error')
                    ->searchable()
                    ->limit(80)
                    ->placeholder('N/A'),

                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('N/A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('bulk_ldap_operation_id')
                    ->label('Bulk Operation')
                    ->options(fn (): array => BulkLdapOperation::query()
                        ->orderByDesc('id')
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->all()),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'already_applied' => 'Already Applied',
                        'conflict' => 'Conflict',
                        'skipped' => 'Skipped',
                    ]),
            ])
            ->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBulkLdapOperationItems::route('/'),
            'view' => Pages\ViewBulkLdapOperationItem::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

}
