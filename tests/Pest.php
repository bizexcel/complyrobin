<?php

use App\Core\Access\Enums\Ability;
use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Notifications\InvitationNotification;
use App\Core\People\Models\Department;
use App\Core\People\Models\Person;
use App\Core\Tasks\Actions\CreateTask;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\RecordRotation;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Arch');

/**
 * Every concrete Eloquent model class under app/, discovered from the PSR-4 path.
 *
 * @return list<class-string<Model>>
 */
function appModels(): array
{
    $models = [];

    foreach (File::allFiles(app_path()) as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! new ReflectionClass($class)->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}

/**
 * A new member of the organisation with the given role.
 */
function memberWithRole(Organisation $organisation, SystemRole $role): User
{
    $user = User::factory()->create();
    Tenancy::run($organisation, function () use ($organisation, $user, $role): void {
        $organisation->users()->attach($user);
        $user->assignRole($role->value);
    });

    return $user;
}

/**
 * The latest invitation link emailed to the address.
 */
function invitationLink(string $email): string
{
    $link = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$link): bool {
        if ($notifiable->routes['mail'] !== $email) {
            return false;
        }

        $link = $notification->url;

        return true;
    });

    return $link;
}

/**
 * An organisation with at least one row in every tenant table, made through the real actions where they exist.
 * A new tenant table fails the coverage test below until it gets a row here.
 */
function populatedOrganisation(string $name): Organisation
{
    Notification::fake();
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, $name);
    $staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    app(GrantModule::class)->handle($staff, $organisation, Module::Credentials, now()->subDay());

    test()->actingAs($owner);

    Tenancy::run($organisation, function () use ($owner, $organisation): void {
        $owner->givePermissionTo(Ability::ManagePeople->value);
        app(InviteMember::class)->handle($owner, Str::lower(Str::random(8)).'@example.com', 'Editor');
        $department = Department::factory()->create();
        $person = Person::factory()->create(['department_id' => $department->id]);
        app(CreateTask::class)->handle($owner, ['title' => 'Rotate a key', 'assignee_id' => $person->id], $person);
        $application = Application::factory()->create(['owner_id' => $person->id]);
        $credential = Credential::factory()->create(['application_id' => $application->id, 'owner_id' => $person->id]);
        app(RecordRotation::class)->handle($owner, $credential, ['notes' => 'Rotated for the fixture']);

        DB::connection('pgsql_migrator')->table('media')->insert([
            'id' => (string) Str::uuid7(), 'organisation_id' => $organisation->id, 'uuid' => (string) Str::uuid7(),
            'model_type' => Person::class, 'model_id' => $person->id, 'collection_name' => 'evidence', 'name' => 'evidence',
            'file_name' => 'evidence.pdf', 'disk' => 'local', 'size' => 1, 'manipulations' => '[]',
            'custom_properties' => '[]', 'generated_conversions' => '[]', 'responsive_images' => '[]',
        ]);
    });

    return $organisation;
}

/**
 * Act as the user inside the organisation, as the panel's middleware would.
 */
function openOrganisationAs(User $user, Organisation $organisation): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($organisation);
    Tenancy::set($organisation);
}
