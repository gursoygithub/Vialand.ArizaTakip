<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Manages user_company_access pivot rows for a single User record.
 *
 * Default visibility for any user is their own employee->company_id; this
 * manager only handles the EXTRA companies granted on top via the pivot.
 * Gated behind user.role.assign because granting cross-company access is
 * the same kind of authority decision as assigning roles.
 */
class CompanyAccessRelationManager extends RelationManager
{
    protected static string $relationship = 'extraCompanies';

    protected static ?string $title = 'Ek Şirket Erişimleri';

    protected static ?string $modelLabel = 'şirket erişimi';

    protected static ?string $pluralModelLabel = 'şirket erişimleri';

    protected static ?string $icon = 'heroicon-o-building-office-2';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('user.role.assign');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('company_id')
                ->label('Şirket')
                ->placeholder('Erişim verilecek şirketi seçiniz')
                ->options(fn (): array => Company::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->toArray())
                ->searchable()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Şirket')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.granted_by')
                    ->label('Veren')
                    ->formatStateUsing(function ($state) {
                        if (!$state) {
                            return '—';
                        }
                        return \App\Models\User::find($state)?->name ?? "#{$state}";
                    }),

                Tables\Columns\TextColumn::make('pivot.created_at')
                    ->label('Verilme Tarihi')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Şirket Erişimi Ekle')
                    ->icon('heroicon-o-plus')
                    ->preloadRecordSelect()
                    ->recordSelect(fn (Forms\Components\Select $select) => $select
                        ->placeholder('Şirket seçiniz')
                        ->searchable())
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        // The pivot-form's `data` carries `recordId`; we only
                        // need to inject granted_by into the pivot insert.
                        $data['granted_by'] = auth()->id();
                        return $data;
                    })
                    ->after(function (Model $record, $livewire) {
                        // After attaching, write granted_by into the pivot row
                        // (Filament's AttachAction writes only the pivot keys
                        // by default).
                        $livewire->getOwnerRecord()
                            ->extraCompanies()
                            ->updateExistingPivot($record->id, [
                                'granted_by' => auth()->id(),
                            ]);
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Kaldır')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Henüz ek şirket erişimi yok')
            ->emptyStateDescription('Bu kullanıcı yalnızca kendi şirketine erişebilir. Daha fazla şirkete erişim için ekleyin.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }

    /**
     * Filter out companies the user already has via their employee record —
     * granting "their own" company explicitly would be a no-op row that
     * confuses scopedCompanyIds() output.
     */
    public function getRelationship(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->getOwnerRecord()->extraCompanies();
    }
}
