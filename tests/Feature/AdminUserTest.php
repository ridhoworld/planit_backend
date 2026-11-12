<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_public_registration_is_disabled(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Self Register User',
            'email' => 'self@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Pendaftaran mandiri dinonaktifkan. Akun baru hanya dapat didaftarkan oleh Administrator.',
        ]);
    }

    public function test_regular_user_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
        ]);

        $response = $this->actingAs($user)->getJson('/api/admin/users');

        $response->assertStatus(403);
    }

    public function test_admin_can_list_and_create_users(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        // 1. Admin lists users
        $listResponse = $this->actingAs($admin)->getJson('/api/admin/users');
        $listResponse->assertStatus(200);

        // 2. Admin creates a new user
        $createResponse = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Staff Accounting',
            'email' => 'staff@planit.app',
            'password' => 'secret123',
            'role' => 'user',
        ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonFragment([
            'email' => 'staff@planit.app',
            'role' => 'user',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'staff@planit.app',
            'role' => 'user',
        ]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}

