<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VisualBackgroundTest extends TestCase
{
    public function test_campus_background_is_an_unchanged_local_public_asset(): void
    {
        $source = base_path('docs/ui-reference/BACKGROUND_APU_Empowering Scientech.jpeg');
        $publicAsset = public_path('images/apu/background-campus.jpeg');

        $this->assertFileExists($source);
        $this->assertFileExists($publicAsset);
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $publicAsset));
    }

    public function test_login_and_application_layouts_supply_the_asset_helper_url(): void
    {
        $authLayout = File::get(resource_path('views/components/layouts/auth.blade.php'));
        $appLayout = File::get(resource_path('views/components/layouts/app.blade.php'));

        foreach ([$authLayout, $appLayout] as $layout) {
            $this->assertStringContainsString(
                "--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')",
                $layout,
            );
        }

        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertSee('Masuk ke akun Anda')
            ->assertSee('images/apu/background-campus.jpeg');
        $this->assertSame(1, substr_count($response->getContent(), 'background-campus.jpeg'));
    }

    public function test_sidebar_and_login_background_rules_preserve_readability_and_responsiveness(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertStringContainsString('.login-shell::before', $css);
        $this->assertStringContainsString('background-image: var(--apu-campus-background)', $css);
        $this->assertStringContainsString('.login-card { width: min(100%, 460px); padding:', $css);
        $this->assertStringContainsString('border: 1px solid rgba(255,255,255,.74)', $css);
        $this->assertStringContainsString('.sidebar::before', $css);
        $this->assertStringContainsString('height: 42%; background-image: var(--apu-campus-background)', $css);
        $this->assertStringContainsString('background-position: 38% bottom; background-size: cover', $css);
        $this->assertStringContainsString('@media (max-width: 900px)', $css);
        $this->assertStringContainsString('.login-shell { grid-template-columns: 1fr; background-position: 40% bottom; }', $css);
        $this->assertStringContainsString('@media (max-width: 620px)', $css);
    }
}
