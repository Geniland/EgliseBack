<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WeekendReadinessRemindersTest extends TestCase
{
    private Role $adminRole;
    private Role $faithfulRole;
    private Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('members');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id');
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('church_id')->nullable();
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('church_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('churches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user1_id');
            $table->unsignedBigInteger('user2_id');
            $table->string('duree')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->nullable();
            $table->unsignedBigInteger('sender_id');
            $table->unsignedBigInteger('recipient_id');
            $table->text('contenu');
            $table->boolean('lu')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_09_24_080000_add_automation_key_to_chat_messages_table.php');
        $migration->up();

        $this->adminRole = Role::create(['name' => 'Administrateur', 'status' => true]);
        $this->faithfulRole = Role::create(['name' => 'Fidèle', 'status' => true]);
        $this->staffRole = Role::create(['name' => 'Responsable', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_saturday_reminder_is_sent_once_to_active_faithful_with_active_members(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 08:00:00', config('app.timezone')));

        $sender = $this->createUser('Administrateur', $this->adminRole, 10);
        $this->createChurch(10, 'Église de test');
        $faithfulOne = $this->createUser('Aline', $this->faithfulRole, 10);
        $faithfulTwo = $this->createUser('David', $this->faithfulRole, 10);
        $inactiveUser = $this->createUser('Compte inactif', $this->faithfulRole, 10, false);
        $staff = $this->createUser('Responsable', $this->staffRole, 10);

        $this->createMember($faithfulOne, 'Aline', 10);
        $this->createMember($faithfulTwo, 'David', 10);
        $this->createMember($inactiveUser, 'Compte', 10);
        $this->createMember($staff, 'Responsable', 10);
        $this->createMember(null, 'Membre sans compte', 10);

        $this->assertSame(0, Artisan::call('church:send-weekend-readiness-reminders'));
        $this->assertSame('Rappels envoyés : 2 | déjà envoyés ou ignorés : 0 | erreurs : 0', trim(Artisan::output()));

        $messages = ChatMessage::query()->orderBy('recipient_id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame([$faithfulOne->id, $faithfulTwo->id], $messages->pluck('recipient_id')->all());

        foreach ($messages as $message) {
            $this->assertSame($sender->id, $message->sender_id);
            $this->assertSame('weekend-readiness:2026-09-26', $message->automation_key);
            $this->assertSame('2026-10-03 08:00:00', $message->expires_at->toDateTimeString());
            $this->assertStringContainsString('demain', mb_strtolower($message->contenu));
            $this->assertTrue(
                str_contains(mb_strtolower($message->contenu), 'habit')
                || str_contains(mb_strtolower($message->contenu), 'tenue')
            );
        }

        $this->artisan('church:send-weekend-readiness-reminders')->assertSuccessful();
        $this->assertSame(2, ChatMessage::query()->count());

        $this->actingAs($faithfulOne, 'sanctum')
            ->getJson('/api/chat/conversations/' . $sender->id)
            ->assertSuccessful()
            ->assertJsonPath('messages.0.sender_id', $sender->id)
            ->assertJsonPath('messages.0.is_mine', false)
            ->assertJsonPath('messages.0.lu', true);
    }

    public function test_sunday_reminder_uses_sunday_messages_and_skips_recipients_without_a_sender(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 08:00:00', config('app.timezone')));

        $sender = $this->createUser('Administrateur', $this->adminRole, 20);
        $this->createChurch(20, 'Église du dimanche');
        $faithful = $this->createUser('Élie', $this->faithfulRole, 20);
        $orphanedChurchFaithful = $this->createUser('Sans responsable', $this->faithfulRole, 30);
        $this->createChurch(30, 'Église sans responsable');
        $this->createMember($faithful, 'Élie', 20);
        $this->createMember($orphanedChurchFaithful, 'Sans', 30);

        $this->artisan('church:send-weekend-readiness-reminders')->assertSuccessful();

        $message = ChatMessage::query()->where('recipient_id', $faithful->id)->firstOrFail();
        $this->assertSame($sender->id, $message->sender_id);
        $this->assertSame('weekend-readiness:2026-09-27', $message->automation_key);
        $this->assertStringContainsString('dimanche', mb_strtolower($message->contenu));
        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_weekend_reminder_is_scheduled_for_eight_on_saturdays_and_sundays(): void
    {
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(function ($event) {
            return str_contains($event->command ?? '', 'church:send-weekend-readiness-reminders');
        });

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * 6,0', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    private function createUser(string $name, Role $role, int $churchId, bool $active = true): User
    {
        $email = str_replace(' ', '.', mb_strtolower($name)) . '.' . uniqid() . '@example.test';

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role_id' => $role->id,
            'status' => $active,
            'church_id' => $churchId,
        ]);
    }

    private function createMember(?User $user, string $firstName, int $churchId, bool $active = true): Member
    {
        return Member::create([
            'user_id' => $user?->id,
            'church_id' => $churchId,
            'first_name' => $firstName,
            'last_name' => 'Test',
            'status' => $active,
        ]);
    }

    private function createChurch(int $id, string $name): void
    {
        DB::table('churches')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
