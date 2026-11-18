<?php

namespace Tests\Feature;

use App\Enums\FormFieldTypeEnum;
use App\Enums\LocationRequirementEnum;
use App\Filament\Resources\TicketTypeResource;
use App\Filament\Resources\TicketTypeResource\Pages\CreateTicketType;
use App\Filament\Resources\TicketTypeResource\Pages\EditTicketType;
use App\Filament\Resources\TicketTypeResource\Pages\ListTicketTypes;
use App\Models\Team;
use App\Models\TicketType;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\GrantsShieldPermissions;
use Tests\TestCase;

class TicketTypeManagementTest extends TestCase
{
    use GrantsShieldPermissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->userWithResourcePermissions(['ticket::type']));
    }

    public function test_admin_creates_a_type_with_custom_form_fields(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateTicketType::class)
            ->fillForm([
                'name' => 'IT Destek',
                'location_requirement' => LocationRequirementEnum::Hidden->value,
                'icon' => 'heroicon-o-computer-desktop',
                'color' => 'info',
                'form_fields' => [
                    [
                        'label' => 'Demirbaş No',
                        'key' => 'asset_no',
                        'type' => FormFieldTypeEnum::Text->value,
                        'required' => true,
                        'help' => null,
                    ],
                    [
                        'label' => 'Cihaz Türü',
                        'key' => 'device_kind',
                        'type' => FormFieldTypeEnum::Select->value,
                        'options' => ['Bilgisayar', 'Yazıcı'],
                        'required' => false,
                        'help' => 'Arızalı cihazı seçin',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $type = TicketType::where('name', 'IT Destek')->sole();
        $this->assertSame(LocationRequirementEnum::Hidden, $type->location_requirement);
        $this->assertCount(2, $type->form_fields);
        $this->assertSame('asset_no', $type->form_fields[0]['key']);
        $this->assertSame(['Bilgisayar', 'Yazıcı'], $type->form_fields[1]['options']);
        $this->assertSame(auth()->id(), $type->created_by);
    }

    public function test_type_name_must_be_unique(): void
    {
        TicketType::factory()->create(['name' => 'Elektrik']);

        Livewire::test(CreateTicketType::class)
            ->fillForm(['name' => 'Elektrik'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_field_keys_must_be_unique_within_a_type(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateTicketType::class)
            ->fillForm([
                'name' => 'Mekanik',
                'form_fields' => [
                    ['label' => 'A', 'key' => 'same_key', 'type' => FormFieldTypeEnum::Text->value],
                    ['label' => 'B', 'key' => 'same_key', 'type' => FormFieldTypeEnum::Text->value],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['form_fields.0.key', 'form_fields.1.key']);

        $this->assertDatabaseMissing('ticket_types', ['name' => 'Mekanik']);

        $undoRepeaterFake();
    }

    public function test_field_key_must_be_a_safe_identifier(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateTicketType::class)
            ->fillForm([
                'name' => 'Temizlik',
                'form_fields' => [
                    ['label' => 'Alan', 'key' => 'Bad Key!', 'type' => FormFieldTypeEnum::Text->value],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['form_fields.0.key' => 'regex']);

        $undoRepeaterFake();
    }

    public function test_select_field_requires_options(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateTicketType::class)
            ->fillForm([
                'name' => 'Satın Alma',
                'form_fields' => [
                    ['label' => 'Kategori', 'key' => 'category', 'type' => FormFieldTypeEnum::Select->value, 'options' => []],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['form_fields.0.options' => 'required']);

        $undoRepeaterFake();
    }

    public function test_list_shows_types(): void
    {
        $types = TicketType::factory()->count(2)->create();

        Livewire::test(ListTicketTypes::class)
            ->assertCanSeeTableRecords($types);
    }

    public function test_type_with_teams_cannot_be_deleted(): void
    {
        $used = TicketType::factory()->create();
        Team::factory()->create(['ticket_type_id' => $used->id]);
        $unused = TicketType::factory()->create();

        $this->assertFalse(TicketTypeResource::canDelete($used));
        $this->assertTrue(TicketTypeResource::canDelete($unused));
    }

    public function test_type_can_be_deactivated(): void
    {
        $type = TicketType::factory()->create();

        Livewire::test(EditTicketType::class, ['record' => $type->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($type->fresh()->is_active);
        $this->assertSame(0, TicketType::active()->count());
    }

    public function test_user_without_permission_cannot_open_types(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(TicketTypeResource::getUrl('index'))->assertForbidden();
    }

    public function test_admin_sets_when_people_must_be_kept_informed(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateTicketType::class)
            ->fillForm([
                'name' => 'IT Destek',
                'location_requirement' => LocationRequirementEnum::Hidden->value,
                'cc_required' => false,
                'form_fields' => [
                    [
                        'label' => 'Konu',
                        'key' => 'device_kind',
                        'type' => FormFieldTypeEnum::Select->value,
                        'options' => ['Bilgisayar', 'Yazılım / Hesap'],
                        'cc_required_options' => ['Yazılım / Hesap'],
                        'required' => true,
                        'help' => null,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $type = TicketType::where('name', 'IT Destek')->sole();
        $this->assertFalse($type->cc_required);
        $this->assertSame(['Yazılım / Hesap'], $type->form_fields[0]['cc_required_options']);
        $this->assertTrue($type->requiresWatchers(['device_kind' => 'Yazılım / Hesap']));
        $this->assertFalse($type->requiresWatchers(['device_kind' => 'Bilgisayar']));

        $type->update(['cc_required' => true]);
        $this->assertTrue($type->requiresWatchers(['device_kind' => 'Bilgisayar']));
    }
}
