<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VisualBackgroundTest extends TestCase
{
    public function test_campus_background_is_an_unchanged_local_public_asset(): void
    {
        $publicAsset = public_path('images/apu/background-campus.jpeg');

        $this->assertFileExists($publicAsset);
        $this->assertSame('image/jpeg', image_type_to_mime_type(exif_imagetype($publicAsset)));
        $this->assertSame([3840, 2160], array_slice(getimagesize($publicAsset), 0, 2));
        $this->assertSame(
            'b674c120ff1ea85e433c42252bd2ee91b2ae740f3666b4f9b89e85f58238630d',
            hash_file('sha256', $publicAsset),
        );
    }

    public function test_login_and_application_layouts_supply_the_asset_helper_url(): void
    {
        $authLayout = File::get(resource_path('views/components/layouts/auth.blade.php'));
        $appLayout = File::get(resource_path('views/components/layouts/app.blade.php'));

        $this->assertStringContainsString(
            "--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')",
            $authLayout,
        );
        $this->assertStringNotContainsString('background-campus.webp', $authLayout);
        $this->assertStringNotContainsString('image-set(', $authLayout);
        $this->assertStringContainsString(
            "--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')",
            $appLayout,
        );

        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertSee('Masuk ke akun Anda')
            ->assertSee('images/apu/background-campus.jpeg');
        $this->assertSame(1, substr_count($response->getContent(), 'background-campus.jpeg'));
        $this->assertStringNotContainsString('background-campus.webp', $response->getContent());
        $this->assertStringNotContainsString('image-set(', $response->getContent());
        $this->assertStringNotContainsString('logo APU - tag.png', $response->getContent());
        $this->assertStringContainsString('type="password"', $response->getContent());
        $this->assertStringContainsString('x-bind:type="visible ? \'text\' : \'password\'"', $response->getContent());
        $this->assertStringContainsString('x-bind:aria-pressed="visible"', $response->getContent());
        $this->assertStringContainsString('class="password-visibility-toggle"', $response->getContent());
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringNotContainsString('x-text="visible', $response->getContent());
    }

    public function test_sidebar_and_login_background_rules_preserve_readability_and_responsiveness(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertStringNotContainsString('.login-shell::before', $css);
        $this->assertStringContainsString('background-image: var(--apu-campus-background)', $css);
        $this->assertStringContainsString('background-position: center top', $css);
        $this->assertStringContainsString('background-size: contain', $css);
        $this->assertDoesNotMatchRegularExpression('/\.login-shell\s*\{[^}]*background-size:\s*cover/s', $css);
        $this->assertStringContainsString('.login-card { width: min(100%, 460px); padding:', $css);
        $this->assertStringContainsString('border: 1px solid rgba(255,255,255,.74)', $css);
        $this->assertStringContainsString('.password-visibility-toggle svg { width: 20px; height: 20px;', $css);
        $this->assertStringContainsString('.sidebar::before', $css);
        $this->assertStringContainsString('height: 42%; background-image: var(--apu-campus-background)', $css);
        $this->assertStringContainsString('background-position: 38% bottom; background-size: cover', $css);
        $this->assertStringContainsString('@media (max-width: 900px)', $css);
        $this->assertStringContainsString('background-position: center top; background-size: 100% auto', $css);
        $this->assertStringContainsString('@media (max-width: 620px)', $css);
    }
}
