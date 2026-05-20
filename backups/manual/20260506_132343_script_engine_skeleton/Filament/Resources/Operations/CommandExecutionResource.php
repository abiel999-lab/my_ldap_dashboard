<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\CommandExecutionResource\Pages;
use App\Models\Operations\CommandExecution;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CommandExecutionResource extends Resource
{
    protected static ?string $model = CommandExecution::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Command Executions';

    protected static ?string $modelLabel = 'Command Execution';

    protected static ?string $pluralModelLabel = 'Command Executions';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Execution')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('command_type')->label('Command Type')->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'pending' => 'gray',
                                'running' => 'info',
                                'success' => 'success',
                                'failed' => 'danger',
                                'blocked' => 'warning',
                                default => 'gray',
                            }),
                        IconEntry::make('safe_mode')->label('Safe Mode')->boolean(),
                        IconEntry::make('preview_mode')->label('Preview Mode')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                        TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('N/A'),
                    ])
                    ->columns(3),

                Section::make('Actor')
                    ->schema([
                        TextEntry::make('actor_name')->label('Actor Name')->placeholder('System / Unknown'),
                        TextEntry::make('actor_email')->label('Actor Email')->placeholder('No email'),
                        TextEntry::make('actor_ip')->label('IP Address')->placeholder('No IP'),
                        TextEntry::make('user_agent')->label('User Agent')->columnSpanFull()->placeholder('No user agent'),
                    ])
                    ->columns(2),

                Section::make('Command')
                    ->schema([
                        TextEntry::make('working_directory')->label('Working Directory')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('command')->label('Command')->columnSpanFull(),
                        TextEntry::make('environment_context')
                            ->label('Environment Context')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Output')
                    ->schema([
                        TextEntry::make('stdout')->label('STDOUT')->placeholder('No stdout')->columnSpanFull(),
                        TextEntry::make('stderr')->label('STDERR')->placeholder('No stderr')->columnSpanFull(),
                        TextEntry::make('exit_code')->label('Exit Code')->placeholder('N/A'),
                        TextEntry::make('error_message')->label('Error Message')->placeholder('No error')->columnSpanFull(),
                    ]),

                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('id'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('command_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('display_command')
                    ->label('Command')
                    ->searchable()
                    ->limit(80),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'gray',
                        'running' => 'info',
                        'success' => 'success',
                        'failed' => 'danger',
                        'blocked' => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                IconColumn::make('safe_mode')->label('Safe')->boolean(),
                IconColumn::make('destructive')->label('Destructive')->boolean(),

                TextColumn::make('exit_code')
                    ->label('Exit')
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->suffix(' ms')
                    ->sortable()
                    ->placeholder('N/A'),

                TextColumn::make('actor_email')
                    ->label('Actor')
                    ->placeholder('System'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('command_type')
                    ->label('Command Type')
                    ->options([
                        'safe_artisan' => 'Safe Artisan',
                        'ldapsearch' => 'ldapsearch',
                        'ldapmodify' => 'ldapmodify',
                        'ldapadd' => 'ldapadd',
                        'ldapdelete' => 'ldapdelete',
                        'ldif_import' => 'LDIF Import',
                        'ldif_export' => 'LDIF Export',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'blocked' => 'Blocked',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommandExecutions::route('/'),
            'view' => Pages\ViewCommandExecution::route('/{record}'),
        ];
    }
}
