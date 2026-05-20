<?php

namespace App\Filament\Resources\Operations\ImportBatchResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
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

    public function table(Table $table): Table
    {
        return $table
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
            ->defaultPaginationPageOption(10);
    }
}
