<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiAuthorizationTest extends TestCase
{
    private string $originalDbConnection;
    private string $isolatedDbConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDbConnection = (string) config('database.default');
        $this->isolatedDbConnection = 'authorization_test_' . spl_object_id($this);
        config([
            'database.connections.' . $this->isolatedDbConnection => array_merge(
                config('database.connections.sqlite'),
                ['database' => ':memory:', 'url' => null]
            ),
            'database.default' => $this->isolatedDbConnection,
        ]);
        \DB::purge($this->isolatedDbConnection);

        foreach (['permission_role', 'events', 'members', 'fonctions', 'churches', 'users', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('module')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id');
            $table->foreignId('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('fonctions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('churches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('parent_church_id')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable();
            $table->foreignId('fonction_id')->nullable();
            $table->unsignedBigInteger('parent_user_id')->nullable();
            $table->unsignedBigInteger('church_id')->nullable();
            $table->string('church_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('status')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('church_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedDbConnection)) {
            \DB::purge($this->isolatedDbConnection);
            config(['database.default' => $this->originalDbConnection]);
        }

        parent::tearDown();
    }

    public function test_faithful_receives_effective_permissions_and_cannot_read_members(): void
    {
        $faithfulRole = $this->createRole(6, 'Fidèle');
        $eventsView = Permission::create(['name' => 'events.view', 'status' => true]);
        $faithfulRole->permissions()->attach($eventsView->id);
        $faithful = $this->createUser($faithfulRole, 'fidèle@example.test');

        $this->actingAs($faithful, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('permissions', ['events.view']);

        foreach ([
            '/api/members',
            '/api/attendance',
            '/api/finance/dashboard',
            '/api/ministries',
            '/api/live-streams',
            '/api/resources',
            '/api/formations',
            '/api/users',
        ] as $path) {
            $this->actingAs($faithful, 'sanctum')
                ->getJson($path)
                ->assertForbidden();
        }
    }

    public function test_event_id_cannot_be_used_to_read_another_users_event(): void
    {
        $role = $this->createRole(5, 'Responsable');
        $eventsView = Permission::create(['name' => 'events.view', 'status' => true]);
        $role->permissions()->attach($eventsView->id);
        $viewer = $this->createUser($role, 'responsable@example.test');
        $otherUser = $this->createUser($role, 'autre@example.test');
        $eventId = \DB::table('events')->insertGetId([
            'created_by' => $otherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/events/' . $eventId)
            ->assertNotFound();
    }

    public function test_disabled_role_permission_does_not_grant_route_access(): void
    {
        $role = $this->createRole(6, 'Fidèle');
        $disabledPermission = Permission::create(['name' => 'events.view', 'status' => false]);
        $role->permissions()->attach($disabledPermission->id);
        $faithful = $this->createUser($role, 'disabled@example.test');

        $this->actingAs($faithful, 'sanctum')
            ->getJson('/api/events')
            ->assertForbidden();
    }

    public function test_user_list_is_limited_to_the_signed_in_users_church_and_team(): void
    {
        $role = $this->createRole(5, 'Responsable');
        $permission = Permission::create(['name' => 'users.view', 'status' => true]);
        $role->permissions()->attach($permission->id);
        $viewer = $this->createUser($role, 'viewer@example.test', 20);
        $child = $this->createUser($role, 'child@example.test', 20, $viewer->id);
        $outside = $this->createUser($role, 'outside@example.test', 30);

        $response = $this->actingAs($viewer, 'sanctum')->getJson('/api/users')->assertOk();

        $response->assertJsonCount(2);
        $this->assertNotContains($outside->email, collect($response->json())->pluck('email')->all());
        $this->assertContains($child->email, collect($response->json())->pluck('email')->all());
    }

    private function createUser(Role $role, string $email, ?int $churchId = null, ?int $parentUserId = null): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'secret-password',
            'role_id' => $role->id,
            'church_id' => $churchId,
            'parent_user_id' => $parentUserId,
            'status' => true,
        ]);
    }

    private function createRole(int $id, string $name): Role
    {
        \DB::table('roles')->insert([
            'id' => $id,
            'name' => $name,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Role::findOrFail($id);
    }
}
