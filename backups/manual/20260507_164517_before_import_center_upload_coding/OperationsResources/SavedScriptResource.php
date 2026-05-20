<?php

namespace App\Filament\Resources\Operations;

use App\Filament\Resources\Operations\SavedScriptResource\Pages;
use App\Models\Operations\SavedScript;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\ScriptPreviewService;
use App\Services\Operations\ScriptOperationDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SavedScriptResource extends Resource
{
    protected static ?string $model = SavedScript::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static string|UnitEnum|null $navigationGroup = '2. Operations';

    protected static ?string $navigationLabel = 'Script Engine';

    protected static ?string $modelLabel = 'Saved Script';

    protected static ?string $pluralModelLabel = 'Script Engine';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Script Identity')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('script_type')
                            ->label('Script Type')
                            ->options([
                                'ldapsearch' => 'ldapsearch',
                                'ldapmodify' => 'ldapmodify',
                                'ldapadd' => 'ldapadd',
                                'ldapdelete' => 'ldapdelete',
                                'ldif_import' => 'LDIF Import',
                                'ldif_export' => 'LDIF Export',
                                'safe_artisan' => 'Safe Artisan',
                            ])
                            ->default('ldapsearch')
                            ->required(),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Draft',
                                'testing_needed' => 'Testing Needed',
                                'production_ready' => 'Production Ready',
                                'disabled' => 'Disabled',
                                'deprecated' => 'Deprecated',
                            ])
                            ->default('draft')
                            ->required(),

                        Select::make('risk_level')
                            ->label('Risk Level')
                            ->options([
                                'low' => 'Low',
                                'medium' => 'Medium',
                                'high' => 'High',
                                'critical' => 'Critical',
                            ])
                            ->default('low')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Safety')
                    ->schema([
                        Toggle::make('safe_mode_required')
                            ->label('Safe Mode Required')
                            ->default(true)
                            ->required(),

                        Toggle::make('preview_only')
                            ->label('Preview Only')
                            ->default(true)
                            ->required(),

                        Toggle::make('destructive')
                            ->label('Destructive')
                            ->default(false)
                            ->helperText('Mark true for ldapmodify, ldapadd, ldapdelete, restore, or any command that changes data.'),
                    ])
                    ->columns(3),

                Section::make('Script')
                    ->schema([
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('script_body')
                            ->label('Script Body')
                            ->rows(14)
                            ->required()
                            ->columnSpanFull()
                            ->helperText('At this stage scripts are previewed only. Destructive execution is intentionally blocked.'),

                        Textarea::make('risk_notes')
                            ->label('Risk Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Script')
                    ->schema([
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('script_type')->label('Type')->badge(),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('risk_level')->label('Risk')->badge(),
                        IconEntry::make('safe_mode_required')->label('Safe Mode Required')->boolean(),
                        IconEntry::make('preview_only')->label('Preview Only')->boolean(),
                        IconEntry::make('destructive')->label('Destructive')->boolean(),
                        TextEntry::make('description')->label('Description')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('script_body')->label('Script Body')->columnSpanFull(),
                        TextEntry::make('risk_notes')->label('Risk Notes')->placeholder('N/A')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('uuid')->label('UUID')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'id',
                    'uuid',
                    'name',
                    'script_type',
                    'status',
                    'risk_level',
                    'safe_mode_required',
                    'preview_only',
                    'destructive',
                    'script_body',
                    'updated_at',
                    'created_at',
                ])
                )
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('script_type')
                    ->label('Type')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'testing_needed' => 'warning',
                        'production_ready' => 'success',
                        'disabled' => 'gray',
                        'deprecated' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('risk_level')
                    ->label('Risk')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'low' => 'success',
                        'medium' => 'warning',
                        'high' => 'danger',
                        'critical' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('safe_mode_required')->label('Safe')->boolean(),
                IconColumn::make('preview_only')->label('Preview')->boolean(),
                IconColumn::make('destructive')->label('Destructive')->boolean(),

                TextColumn::make('display_script')
                    ->label('Script Preview')
                    ->limit(40),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('script_type')
                    ->label('Type')
                    ->options([
                        'ldapsearch' => 'ldapsearch',
                        'ldapmodify' => 'ldapmodify',
                        'ldapadd' => 'ldapadd',
                        'ldapdelete' => 'ldapdelete',
                        'ldif_import' => 'LDIF Import',
                        'ldif_export' => 'LDIF Export',
                        'safe_artisan' => 'Safe Artisan',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'testing_needed' => 'Testing Needed',
                        'production_ready' => 'Production Ready',
                        'disabled' => 'Disabled',
                        'deprecated' => 'Deprecated',
                    ]),

                SelectFilter::make('risk_level')
                    ->label('Risk')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'critical' => 'Critical',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('previewScript')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Preview script?')
                    ->modalDescription('This will validate and preview the script. It will not execute destructive LDAP changes.')
                    ->action(function (SavedScript $record): void {
                        $execution = app(ScriptPreviewService::class)->preview($record);

                        Notification::make()
                            ->title($execution->status === 'previewed' ? 'Script preview created' : 'Script preview blocked')
                            ->body('Command Execution #'.$execution->id.' status: '.$execution->status)
                            ->{$execution->status === 'previewed' ? 'success' : 'warning'}()
                            ->send();
                    }),

                Action::make('executeLdapSearch')
                    ->label('Queue ldapsearch')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (SavedScript $record): bool => $record->script_type === 'ldapsearch' && ! $record->destructive)
                    ->requiresConfirmation()
                    ->modalHeading('Queue ldapsearch in safe mode?')
                    ->modalDescription('This creates an Operation Job and runs read-only ldapsearch in the script queue. It will not modify LDAP data.')
                    ->action(function (SavedScript $record): void {
                        $operationJob = app(ScriptOperationDispatcher::class)->queueLdapSearch($record);

                        Notification::make()
                            ->title('ldapsearch queued')
                            ->body('Operation Job #'.$operationJob->id.' was created. Check Operation Jobs for progress.')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->after(function (SavedScript $record): void {
                        app(AuditLogger::class)->log([
                            'module' => 'operations.script_engine',
                            'action' => 'delete_saved_script',
                            'status' => 'success',
                            'target_type' => SavedScript::class,
                            'target_key' => (string) $record->id,
                            'before_value' => $record->toArray(),
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavedScripts::route('/'),
            'create' => Pages\CreateSavedScript::route('/create'),
            'view' => Pages\ViewSavedScript::route('/{record}'),
            'edit' => Pages\EditSavedScript::route('/{record}/edit'),
        ];
    }
}
