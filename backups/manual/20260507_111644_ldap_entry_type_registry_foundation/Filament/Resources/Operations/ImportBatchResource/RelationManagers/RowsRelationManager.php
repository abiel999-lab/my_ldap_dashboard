<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\RelationManagers;

use App\Models\Operations\ImportRow;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Preview Rows';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Row Summary')
                    ->schema([
                        TextEntry::make('row_number')
                            ->label('Row Number'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'valid' => 'success',
                                'invalid' => 'danger',
                                'duplicate' => 'warning',
                                'conflict' => 'warning',
                                default => 'gray',
                            }),

                        TextEntry::make('action_plan')
                            ->label('Action Plan')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'create' => 'success',
                                'update' => 'warning',
                                'skip' => 'gray',
                                'fail' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('target_identifier')
                            ->label('Target Identifier')
                            ->placeholder('N/A'),

                        TextEntry::make('target_dn')
                            ->label('Target DN')
                            ->placeholder('N/A')
                            ->columnSpanFull(),

                        TextEntry::make('message')
                            ->label('Message')
                            ->placeholder('N/A')
                            ->columnSpanFull(),

                        TextEntry::make('conflict_reason')
                            ->label('Conflict Reason')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Validation')
                    ->schema([
                        TextEntry::make('display_validation_errors')
                            ->label('Validation Errors')
                            ->placeholder('No validation errors.')
                            ->columnSpanFull(),

                        TextEntry::make('display_warnings')
                            ->label('Warnings')
                            ->placeholder('No warnings.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Payload')
                    ->schema([
                        TextEntry::make('raw_payload_json')
                            ->label('Raw Payload')
                            ->columnSpanFull(),

                        TextEntry::make('mapped_payload_json')
                            ->label('Mapped Payload')
                            ->columnSpanFull(),

                        TextEntry::make('payload_hash')
                            ->label('Payload Hash')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('uuid')
                            ->label('UUID')
                            ->columnSpanFull(),

                        TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime(),

                        TextEntry::make('updated_at')
                            ->label('Updated At')
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->select([
                    'id',
                    'uuid',
                    'import_batch_id',
                    'row_number',
                    'status',
                    'action_plan',
                    'target_dn',
                    'target_identifier',
                    'raw_payload',
                    'mapped_payload',
                    'validation_errors',
                    'warnings',
                    'payload_hash',
                    'conflict_reason',
                    'message',
                    'created_at',
                    'updated_at',
                ])
                ->orderBy('row_number'))
            ->columns([
                TextColumn::make('row_number')
                    ->label('Row')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'valid' => 'success',
                        'invalid' => 'danger',
                        'duplicate' => 'warning',
                        'conflict' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('action_plan')
                    ->label('Plan')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'warning',
                        'skip' => 'gray',
                        'fail' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('target_identifier')
                    ->label('Identifier')
                    ->searchable()
                    ->placeholder('N/A'),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->searchable()
                    ->limit(70)
                    ->placeholder('N/A'),

                TextColumn::make('message')
                    ->label('Message')
                    ->limit(80)
                    ->placeholder('N/A'),

                TextColumn::make('conflict_reason')
                    ->label('Conflict')
                    ->limit(60)
                    ->placeholder('N/A'),
            ])
            ->defaultPaginationPageOption(10)
            ->recordActions([
                ViewAction::make()
                    ->label('Detail'),
            ]);
    }
}
