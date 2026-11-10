<?php

namespace Tests\Feature\Users;

use App\Models\AuditActivity;
use App\Models\User;
use App\Models\UserRoleChange;
use App\Services\UserRoleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserRoleUpdateTest extends TestCase
{
    private ?string $schema = null;
    private User $admin;
    private User $user;
    private Role $oldRole;
    private Role $newRole;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('CASE_REPORT_TEST_PG_SOCKET');
        if ($socket) {
            $this->assertStringStartsWith('/private/tmp/eris-case-tests.', $socket);
            $this->schema = 'user_role_test_'.bin2hex(random_bytes(6));
            config(['database.default' => 'role_tests', 'database.connections.role_tests' => [
                'driver' => 'pgsql', 'host' => $socket, 'port' => 55439, 'database' => 'postgres',
                'username' => 'case_tests', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema,
            ]]);
            DB::purge('role_tests');
            DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        } else {
            if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                $this->markTestSkipped('Requires isolated SQLite or CASE_REPORT_TEST_PG_SOCKET.');
            }
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }

        config(['activitylog.database_connection' => null]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            foreach (['first_name', 'middle_name', 'last_name', 'address', 'phone_no', 'gender', 'email', 'date_of_birth', 'password', 'login_status'] as $field) {
                $table->string($field)->nullable();
            }
            $table->boolean('is_blocked')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        (require database_path('migrations/2023_11_27_133816_create_permission_tables.php'))->up();
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id(); $table->string('log_name')->nullable(); $table->text('description');
            $table->nullableMorphs('subject'); $table->nullableMorphs('causer');
            $table->json('properties')->nullable(); $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable(); $table->timestamps();
        });
        (require database_path('migrations/2026_11_09_000001_create_user_role_changes_table.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'View User', 'guard_name' => 'web']);
        Permission::create(['name' => 'Update User', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'ROLE ADMIN', 'guard_name' => 'web']);
        $this->oldRole = Role::create(['name' => 'ROLE HOSPITAL USER', 'guard_name' => 'web']);
        $this->newRole = Role::create(['name' => 'ROLE MEDICAL BOARD', 'guard_name' => 'web']);
        $this->admin = $this->makeUser('Administrator');
        $this->admin->assignRole($adminRole);
        $this->user = $this->makeUser('Hospital');
        $this->user->assignRole($this->oldRole);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function makeUser(string $name): User
    {
        return User::create(['first_name' => $name, 'middle_name' => 'Test', 'last_name' => 'User',
            'address' => 'Zanzibar', 'phone_no' => '+255777000000', 'gender' => 'Male',
            'date_of_birth' => '1990-01-01', 'email' => strtolower($name).'@example.test']);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['first_name' => 'Updated', 'middle_name' => 'Test', 'last_name' => 'User',
            'address' => 'Zanzibar', 'phone_no' => '+255777000000', 'gender' => 'Male',
            'date_of_birth' => '1990-01-01', 'role_id' => $this->newRole->id];
    }

    public function test_update_saves_role_and_removes_stale_inherited_permissions(): void
    {
        $old = Permission::create(['name' => 'Old Role Permission', 'guard_name' => 'web']);
        $new = Permission::create(['name' => 'New Role Permission', 'guard_name' => 'web']);
        $custom = Permission::create(['name' => 'Custom Permission', 'guard_name' => 'web']);
        $this->oldRole->givePermissionTo($old);
        $this->newRole->givePermissionTo($new);
        $this->user->givePermissionTo([$old, $custom]);

        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload(['email' => 'updated@example.test']))
            ->assertOk()->assertJsonPath('statusCode', 201);
        $user = $this->user->fresh();
        $this->assertSame('updated@example.test', $user->email);
        $this->assertTrue($user->hasRole($this->newRole));
        $this->assertFalse($user->hasRole($this->oldRole));
        $this->assertFalse($user->hasPermissionTo($old));
        $this->assertTrue($user->hasPermissionTo($new));
        $this->assertTrue($user->hasPermissionTo($custom));
        $change = UserRoleChange::firstOrFail();
        $this->assertSame($this->oldRole->name, $change->previous_roles[0]['name']);
        $this->assertSame($this->newRole->name, $change->new_roles[0]['name']);
        $this->assertEquals($this->admin->id, $change->changed_by);
        $this->getJson('/api/userAccounts/'.$user->id.'/role-history')->assertOk()
            ->assertJsonPath('data.0.previous_roles.0.name', $this->oldRole->name);
    }

    public function test_all_changes_are_preserved_when_a_user_returns_to_an_old_role(): void
    {
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload())->assertOk();
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload(['role_id' => $this->oldRole->id]))->assertOk();
        $this->assertDatabaseCount('user_role_changes', 2);
        $this->assertSame([$this->oldRole->name, $this->newRole->name], UserRoleChange::orderBy('id')->get()->map(fn ($row) => $row->previous_roles[0]['name'])->all());
    }

    public function test_unchanged_role_and_profile_only_edits_do_not_create_false_changes(): void
    {
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload(['role_id' => $this->oldRole->id]))->assertOk();
        $payload = $this->payload(); unset($payload['role_id']);
        $this->putJson('/api/userAccounts/'.$this->user->id, $payload)->assertOk();
        $this->assertDatabaseCount('user_role_changes', 0);
        $this->assertTrue($this->user->fresh()->hasRole($this->oldRole));
        $this->assertSame($this->user->email, $this->user->fresh()->email);
    }

    public function test_email_update_is_saved_without_changing_the_role(): void
    {
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload([
            'email' => 'new-address@example.test',
            'role_id' => $this->oldRole->id,
        ]))->assertOk()->assertJsonPath('statusCode', 201);

        $user = $this->user->fresh();
        $this->assertSame('new-address@example.test', $user->email);
        $this->assertTrue($user->hasRole($this->oldRole));
        $this->assertDatabaseCount('user_role_changes', 0);
    }

    public function test_saving_the_current_email_is_allowed(): void
    {
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload([
            'email' => $this->user->email,
            'role_id' => $this->oldRole->id,
        ]))->assertOk()->assertJsonPath('statusCode', 201);

        $this->assertSame($this->user->email, $this->user->fresh()->email);
        $this->assertSame('Updated', $this->user->fresh()->first_name);
    }

    public function test_invalid_or_taken_emails_cannot_change_the_user(): void
    {
        foreach (['invalid-email', '', null, $this->admin->email, str_repeat('a', 250).'@example.test'] as $email) {
            $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload(['email' => $email]))
                ->assertUnprocessable()->assertJsonValidationErrors(['email']);

            $user = $this->user->fresh();
            $this->assertSame($this->user->email, $user->email);
            $this->assertSame('Hospital', $user->first_name);
            $this->assertTrue($user->hasRole($this->oldRole));
            $this->assertDatabaseCount('user_role_changes', 0);
        }
    }

    public function test_invalid_role_and_unauthorized_requests_cannot_change_the_user(): void
    {
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload(['role_id' => 999999]))->assertUnprocessable();
        $this->actingAs($this->user, 'sanctum');
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload())->assertUnauthorized();
        $this->getJson('/api/userAccounts/'.$this->admin->id.'/role-history')->assertForbidden();
        $this->assertDatabaseCount('user_role_changes', 0);
        $this->assertSame('Hospital', $this->user->fresh()->first_name);
    }

    public function test_activity_keeps_the_role_at_action_time_after_later_changes(): void
    {
        $this->actingAs($this->user, 'sanctum');
        activity('audit')->causedBy($this->user)->log('Earlier hospital action');
        $id = AuditActivity::latest('id')->value('id');
        $this->actingAs($this->admin, 'sanctum');
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload())->assertOk();
        $this->assertSame($this->oldRole->name, AuditActivity::findOrFail($id)->properties['actor_snapshot']['roles'][0]['name']);
        $this->getJson('/api/audit-logs/'.$id)->assertOk()->assertJsonPath('data.actor_snapshot.roles.0.name', $this->oldRole->name);
    }

    public function test_a_failed_history_write_rolls_back_profile_and_role_changes(): void
    {
        Schema::rename('user_role_changes', 'unavailable_role_history');
        $this->putJson('/api/userAccounts/'.$this->user->id, $this->payload(['email' => 'rolled-back@example.test']))->assertServerError();
        $this->assertSame('Hospital', $this->user->fresh()->first_name);
        $this->assertSame($this->user->email, $this->user->fresh()->email);
        $this->assertTrue($this->user->fresh()->hasRole($this->oldRole));
    }

    public function test_initial_assignment_is_recorded(): void
    {
        $user = $this->makeUser('New');
        app(UserRoleService::class)->assign($user, $this->oldRole);
        $change = UserRoleChange::where('user_id', $user->id)->firstOrFail();
        $this->assertSame([], $change->previous_roles);
        $this->assertSame($this->oldRole->name, $change->new_roles[0]['name']);
    }

    public function test_self_role_change_records_the_role_that_authorized_the_action(): void
    {
        $this->putJson('/api/userAccounts/'.$this->admin->id, $this->payload())->assertOk();
        $change = UserRoleChange::firstOrFail();
        $this->assertSame('ROLE ADMIN', $change->changed_by_roles[0]['name']);
        $event = AuditActivity::where('description', 'User role changed')->firstOrFail();
        $this->assertSame('ROLE ADMIN', $event->properties['actor_snapshot']['roles'][0]['name']);
        $this->assertTrue($this->admin->fresh()->hasRole($this->newRole));
    }
}
