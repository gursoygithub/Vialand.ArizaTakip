<?php

namespace Tests\Feature;

use App\Enums\ActiveStatusEnum;
use App\Enums\TaskPriorityEnum;
use App\Enums\UserStatusEnum;
use App\Exceptions\TicketWorkflowException;
use App\Exports\EmployeeEmailIssuesExport;
use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\TeamResource\Pages\CreateTeam;
use App\Filament\Resources\TeamResource\Pages\ListTeams;
use App\Filament\Resources\TeamResource\Pages\ViewTeam;
use App\Filament\Resources\TeamTicketResource;
use App\Filament\Resources\TeamTicketResource\Pages\CreateTeamTicket;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Listeners\BlockNonCorporateMail;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TeamTicketNotification;
use App\Services\TicketWorkflowService;
use App\Settings\GeneralSettings;
use App\Support\CompanyEmail;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Mail only goes to company domains (Sistem Ayarları); team and CC people
 * need a company address; Personeller shows and exports the HR addresses
 * that need fixing.
 */
class CompanyEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        GeneralSettings::fake(['mail_allowed_domains' => ['gursoygrup.com.tr', 'vialand.com']]);
    }

    private function employee(string $email, array $attributes = []): Employee
    {
        return Employee::factory()->create(['email' => $email, 'status' => ActiveStatusEnum::ACTIVE] + $attributes);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => UserStatusEnum::ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_addresses_are_classified_against_the_company_domains(): void
    {
        $this->assertSame(CompanyEmail::CORPORATE, CompanyEmail::status('Ali.Veli@GursoyGrup.com.tr'));
        $this->assertSame(CompanyEmail::CORPORATE, CompanyEmail::status('ali@vialand.com'));
        $this->assertSame(CompanyEmail::PERSONAL, CompanyEmail::status('ali@gmail.com'));
        $this->assertSame(CompanyEmail::INVALID, CompanyEmail::status('aligmail.com'));
        $this->assertSame(CompanyEmail::INVALID, CompanyEmail::status('ali@gmail .com'));
        $this->assertSame(CompanyEmail::MISSING, CompanyEmail::status(''));
        $this->assertSame(CompanyEmail::MISSING, CompanyEmail::status(null));
    }

    public function test_mail_to_a_personal_address_is_skipped_and_logged(): void
    {
        Log::spy();
        $notification = $this->createMock(TeamTicketNotification::class);
        $listener = new BlockNonCorporateMail;

        $personal = (new AnonymousNotifiable)->route('mail', 'ali@gmail.com');
        $company = (new AnonymousNotifiable)->route('mail', 'ali@gursoygrup.com.tr');

        $this->assertFalse($listener->handle(new NotificationSending($personal, $notification, 'mail')));
        $this->assertNull($listener->handle(new NotificationSending($company, $notification, 'mail')));
        $this->assertNull($listener->handle(new NotificationSending($personal, $notification, 'database')), 'the bell still goes');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['address'] === 'ali@gmail.com')->once();
    }

    public function test_team_people_need_a_company_address(): void
    {
        $this->actingAs($this->admin());
        $type = TicketType::factory()->create();
        $supervisor = $this->employee('amir@gursoygrup.com.tr');
        $personal = $this->employee('ulas@gmail.com', ['name' => 'Ulaş Demir']);

        Livewire::test(CreateTeam::class)
            ->fillForm([
                'ticket_type_id' => $type->id,
                'name' => 'Yazılım Ekibi',
                'supervisor_employee_id' => $supervisor->id,
                'members' => [$personal->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['members'])
            ->assertSee('Ulaş Demir');

        $this->assertDatabaseMissing('teams', ['name' => 'Yazılım Ekibi']);
    }

    public function test_team_screens_name_people_without_a_company_address(): void
    {
        $this->actingAs($this->admin());
        $team = Team::factory()->create(['supervisor_employee_id' => $this->employee('amir@gursoygrup.com.tr')->id]);
        $personal = $this->employee('ulas@gmail.com', ['name' => 'Ulaş Demir', 'company_name' => null]);
        $company = $this->employee('ali@gursoygrup.com.tr', ['name' => 'Ali Kurumsal', 'company_name' => null]);
        $team->members()->attach([$personal->id, $company->id]);

        Livewire::test(ViewTeam::class, ['record' => $team->getKey()])
            ->assertSee(__('ui.team_people_without_email_named', ['names' => 'Ulaş Demir']));

        $this->assertSame('Ulaş Demir ⚠ '.__('ui.no_company_email'), TeamResource::employeeLabel($personal));

        $personal->update(['status' => ActiveStatusEnum::INACTIVE]);
        $this->assertSame('Ulaş Demir ⚠ '.__('ui.employee_inactive_short'), TeamResource::employeeLabel($personal->fresh()));
        Livewire::test(ViewTeam::class, ['record' => $team->getKey()])
            ->assertSee(__('ui.team_people_inactive_named', ['names' => 'Ulaş Demir']))
            ->assertFormSet(fn (array $state): bool => in_array($personal->id, $state['members']));
        $this->assertSame('Ali Kurumsal', TeamResource::employeeLabel($company));

        Livewire::test(ListTeams::class)
            ->assertSeeHtml(trim(json_encode('aktif değil (ayrılmış olabilir): Ulaş Demir'), '"'));
    }

    public function test_cc_needs_a_company_address_and_the_picker_offers_only_those(): void
    {
        $requester = User::factory()->create(['email' => 'talep@gursoygrup.com.tr', 'status' => UserStatusEnum::ACTIVE]);
        $this->employee('talep@gursoygrup.com.tr');
        $this->actingAs($requester);
        $team = Team::factory()->create(['supervisor_employee_id' => $this->employee('amir@gursoygrup.com.tr')->id]);
        $company = $this->employee('mudur@vialand.com');
        $personal = $this->employee('mudur@hotmail.com');

        $candidates = TeamTicketResource::watcherCandidates()->pluck('id');
        $this->assertTrue($candidates->contains($company->id));
        $this->assertFalse($candidates->contains($personal->id));

        $this->expectException(TicketWorkflowException::class);
        app(TicketWorkflowService::class)->open($requester, $team, TaskPriorityEnum::Medium, 'Yazıcı arızası.', [], [], [$personal->id]);
    }

    public function test_personnel_list_filters_by_email_status(): void
    {
        foreach (['view_any_employee', 'view_employee', 'view_all_employees'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->actingAs($this->admin());
        $company = $this->employee('ali@gursoygrup.com.tr');
        $personal = $this->employee('veli@gmail.com');
        $invalid = $this->employee('kullanmıyor');
        $missing = $this->employee('');
        $twinA = $this->employee('ortak@gursoygrup.com.tr');
        $twinB = $this->employee('ortak@gursoygrup.com.tr');

        Livewire::test(ListEmployees::class)
            ->filterTable('email_status', CompanyEmail::PERSONAL)
            ->assertCanSeeTableRecords([$personal])
            ->assertCanNotSeeTableRecords([$company, $invalid, $missing])
            ->filterTable('email_status', CompanyEmail::INVALID)
            ->assertCanSeeTableRecords([$invalid])
            ->assertCanNotSeeTableRecords([$personal, $missing])
            ->filterTable('email_status', CompanyEmail::MISSING)
            ->assertCanSeeTableRecords([$missing])
            ->assertCanNotSeeTableRecords([$company])
            ->filterTable('email_status', 'duplicate')
            ->assertCanSeeTableRecords([$twinA, $twinB])
            ->assertCanNotSeeTableRecords([$company, $personal]);
    }

    public function test_hr_export_lists_only_addresses_to_fix(): void
    {
        $this->employee('ali@gursoygrup.com.tr', ['name' => 'Ali Kurumsal']);
        $this->employee('veli@gmail.com', ['name' => 'Veli Kişisel']);
        $this->employee('', ['name' => 'Ayşe Adressiz']);
        $this->employee('ortak@gursoygrup.com.tr', ['name' => 'Can Mükerrer']);
        $this->employee('ortak@gursoygrup.com.tr', ['name' => 'Cem Mükerrer']);

        $export = new EmployeeEmailIssuesExport;
        $rows = $export->query()->get()->map(fn (Employee $employee): array => $export->map($employee))->keyBy(1);

        $this->assertEqualsCanonicalizing(['Veli Kişisel', 'Ayşe Adressiz', 'Can Mükerrer', 'Cem Mükerrer'], $rows->keys()->all());
        $this->assertSame(__('ui.email_status_personal'), $rows['Veli Kişisel'][5]);
        $this->assertSame(2, $rows['Can Mükerrer'][6]);
    }

    public function test_export_downloads_from_the_personnel_list(): void
    {
        Excel::fake();
        foreach (['view_any_employee', 'view_employee'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->actingAs($this->admin());

        Livewire::test(ListEmployees::class)->callAction('emailIssues');

        Excel::assertDownloaded('personel-eposta-sorunlari-'.now()->format('Y-m-d').'.xlsx');
    }

    public function test_domains_are_managed_on_system_settings(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['mail_allowed_domains' => ['@GuryApi.com', 'gursoygrup.com.tr']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['guryapi.com', 'gursoygrup.com.tr'], app(GeneralSettings::class)->mail_allowed_domains);
    }

    public function test_user_without_a_company_address_is_told_on_the_request_form(): void
    {
        $personal = User::factory()->create(['email' => 'ali@gmail.com', 'status' => UserStatusEnum::ACTIVE]);
        $personal->givePermissionTo(['view_any_team::ticket', 'view_team::ticket', 'create_team::ticket']);
        $this->actingAs($personal);

        Livewire::test(CreateTeamTicket::class)
            ->assertSee(__('ui.own_email_not_company', ['email' => 'ali@gmail.com']));
        Livewire::test(NotificationPreferences::class)
            ->assertSee(__('ui.own_email_not_company', ['email' => 'ali@gmail.com']));

        $company = User::factory()->create(['email' => 'veli@gursoygrup.com.tr', 'status' => UserStatusEnum::ACTIVE]);
        $company->givePermissionTo(['view_any_team::ticket', 'view_team::ticket', 'create_team::ticket']);
        $this->actingAs($company);

        Livewire::test(CreateTeamTicket::class)
            ->assertDontSee(__('ui.own_email_follow_bell'));
    }

    public function test_users_list_marks_and_filters_addresses(): void
    {
        $this->actingAs($admin = $this->admin());
        $admin->update(['email' => 'admin@gursoygrup.com.tr']);
        $personal = User::factory()->create(['email' => 'ayse@hotmail.com', 'username' => 'ayse1', 'status' => UserStatusEnum::ACTIVE, 'created_by' => $admin->id]);
        $company = User::factory()->create(['email' => 'ayse@vialand.com', 'username' => 'ayse2', 'status' => UserStatusEnum::ACTIVE, 'created_by' => $admin->id]);

        Livewire::test(ListUsers::class)
            ->filterTable('email_status', CompanyEmail::PERSONAL)
            ->assertCanSeeTableRecords([$personal])
            ->assertCanNotSeeTableRecords([$company]);
    }
}
