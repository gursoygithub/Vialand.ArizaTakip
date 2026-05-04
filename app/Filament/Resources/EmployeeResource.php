<?php

namespace App\Filament\Resources;

use App\Enums\TaskStatusEnum;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use App\Models\SlaPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getModelLabel(): string
    {
        return __('ui.employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.employees');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.user_management');
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getEloquentQuery()->count();
    }

    // EmployeeResource.php
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if ($user?->hasRole('super_admin') || $user?->can('view_all_employees')) {
            return parent::getEloquentQuery();
        }

        $employeeId = $user?->employee?->id;

        $memberEmployeeIds = \App\Models\GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->where('groups.employee_id', $employeeId)
            ->whereNull('group_members.deleted_at')
            ->whereNull('groups.deleted_at')
            ->pluck('group_members.employee_id')
            ->push($employeeId)
            ->filter()
            ->unique();

        return parent::getEloquentQuery()->whereIn('id', $memberEmployeeIds);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // "Worst-performers first" — managers open this list to find
            // who needs attention. nulls (no data yet) sort first by
            // default for asc, surfacing untracked employees too.
            ->defaultSort('performance_score', 'asc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.email'))
                    ->searchable()
                    ->sortable(),

                // Lifetime ticket volume (any status). Uses the modern
                // `tickets` relation; `tasks` is a legacy alias.
                Tables\Columns\TextColumn::make('tickets_count')
                    ->label('Toplam Talep')
                    ->counts('tickets')
                    ->badge()
                    ->sortable(),

                // Active workload — currently on this person's plate.
                Tables\Columns\TextColumn::make('active_tickets_count')
                    ->label('Aktif')
                    ->counts([
                        'tickets as active_tickets_count' => fn (Builder $q) =>
                            $q->whereIn('status', [
                                TaskStatusEnum::OPEN,
                                TaskStatusEnum::ASSIGNED,
                                TaskStatusEnum::IN_PROGRESS,
                                TaskStatusEnum::ON_HOLD,
                            ]),
                    ])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),

                // SLA breaches still on the assignee — terminal-statuses
                // excluded so this is "active breach surface", not history.
                Tables\Columns\TextColumn::make('breached_tickets_count')
                    ->label('İhlal')
                    ->counts([
                        'tickets as breached_tickets_count' => fn (Builder $q) =>
                            $q->where('sla_breached', true)
                              ->whereNotIn('status', [
                                  TaskStatusEnum::RESOLVED,
                                  TaskStatusEnum::CLOSED,
                                  TaskStatusEnum::CANCELLED,
                              ]),
                    ])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

                // SLA score. Null = no sealed data yet → render "—" rather
                // than a misleading "0%".
                Tables\Columns\TextColumn::make('performance_score')
                    ->label('SLA Başarısı')
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : round($state, 1) . '%')
                    ->badge()
                    ->sortable()
                    ->color(function ($record) {
                        if (is_null($record->performance_score)) {
                            return 'gray';
                        }
                        return $record->performance_score >= ($record->current_threshold ?? 0)
                            ? 'success'
                            : 'danger';
                    }),

                Tables\Columns\TextColumn::make('current_threshold')
                    ->label(__('ui.sla_target_threshold'))
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : '%' . round($state, 1))
                    ->description('Yönetici Hedefi')
                    ->sortable(),

                // Three-state: success (above threshold), danger (below),
                // neutral gray when there's no performance data yet.
                Tables\Columns\IconColumn::make('is_competent')
                    ->label('Yeterlilik')
                    ->state(function ($record) {
                        if (is_null($record->performance_score)) {
                            return null;
                        }
                        return $record->performance_score >= ($record->current_threshold ?? 0);
                    })
                    ->icon(fn ($state) => match (true) {
                        $state === null => 'heroicon-o-minus-circle',
                        $state === true => 'heroicon-o-check-badge',
                        default         => 'heroicon-o-exclamation-triangle',
                    })
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state === true => 'success',
                        default         => 'danger',
                    }),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TicketsRelationManager::class,
            RelationManagers\GroupMembershipsRelationManager::class,
            RelationManagers\SlaPoliciesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        // Read-only resource: employee records come from LDAP, never edited
        // in-app. canEdit/canDelete already return false; dropping the
        // create/edit routes also prevents typed-URL access to a blank
        // form that would persist a half-empty record.
        return [
            'index' => Pages\ListEmployees::route('/'),
            'view'  => Pages\ViewEmployee::route('/{record}'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }
}
