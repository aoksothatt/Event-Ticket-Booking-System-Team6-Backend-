<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PlatformSettingsBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_both_brand_colors_and_upload_a_public_logo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::fromUser($admin);

        $this->withToken($token)
            ->putJson('/api/admin/settings', [
                'settings' => [
                    'appearance.primary_color' => '#24ae7b',
                    'appearance.secondary_color' => '#334155',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.appearance.primary_color', '#24ae7b')
            ->assertJsonPath('data.settings.appearance.secondary_color', '#334155');

        $this->assertDatabaseHas('settings', [
            'key' => 'appearance.primary_color',
            'value' => '#24ae7b',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'appearance.secondary_color',
            'value' => '#334155',
        ]);

        $upload = $this->withToken($token)
            ->post('/api/admin/settings/logo', [
                'image' => UploadedFile::fake()->image('brand.png'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $path = $upload->json('data.path');
        $this->assertIsString($path);
        $this->assertStringStartsWith('logos/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('settings', [
            'key' => 'appearance.logo',
            'value' => $path,
        ]);

        $this->getJson('/api/settings/public')
            ->assertOk()
            ->assertJsonPath('data.settings.appearance.logo', $path)
            ->assertJsonPath('data.settings.appearance.primary_color', '#24ae7b')
            ->assertJsonPath('data.settings.appearance.secondary_color', '#334155');
    }

    public function test_public_settings_are_readable_but_admin_updates_require_authentication(): void
    {
        $this->getJson('/api/settings/public')->assertOk();
        $this->putJson('/api/admin/settings', [
            'settings' => ['appearance.primary_color' => '#24ae7b'],
        ])->assertUnauthorized();
    }
}
