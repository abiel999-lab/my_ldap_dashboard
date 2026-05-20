<?php

namespace App\Filament\Resources\Operations\OperationJobResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Job Items';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('uuid')->label('UUID')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('operation_job_id')->label('Operation Job ID'),
                        TextEntry::make('display_target')->label('Target'),
                        TextEntry::make('target_dn')->label('Target DN')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_action')->label('Action')->badge(),
                        TextEntry::make('display_status')->label('Status')->badge(),
                    ])
                    ->columns(2),

                Section::make('Payload')
                    ->schema([
                        TextEntry::make('input_payload')
                            ->label('Input Payload')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),

                        TextEntry::make('output_payload')
                            ->label('Output Payload')
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'N/A')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),

                Section::make('Execution')
                    ->schema([
                        TextEntry::make('attempt_count')->label('Attempts')->placeholder('0'),
                        TextEntry::make('payload_hash')->label('Payload Hash')->placeholder('N/A')->columnSpanFull(),
                        TextEntry::make('display_error_message')->label('Error Message')->placeholder('No error')->columnSpanFull(),
                        TextEntry::make('started_at')->label('Started At')->dateTime()->placeholder('N/A'),
                        TextEntry::make('finished_at')->label('Finished At')->dateTime()->placeholder('N/A'),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('display_target')
                    ->label('Target')
                    ->limit(50),

                TextColumn::make('target_dn')
                    ->label('Target DN')
                    ->limit(60)
                    ->placeholder('N/A'),

                TextColumn::make('display_action')
                    ->label('Action')
                    ->badge(),

                TextColumn::make('display_status')
                    ->label('Status')
                    ->badge(),

                TextColumn::make('attempt_count')
                    ->label('Attempts')
                    ->sortable()
                    ->placeholder('0'),

                TextColumn::make('display_error_message')
                    ->label('Error')
                    ->limit(70)
                    ->placeholder('No error'),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->placeholder('N/A'),

                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('N/A'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
