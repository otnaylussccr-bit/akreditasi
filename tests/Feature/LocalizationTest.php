<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Support\LocaleResolver;
use App\Support\LocalizedLabel;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_english_is_the_default_and_indonesian_can_be_selected(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Sign in to your account')
            ->assertSee('Bahasa Indonesia');

        $this->post(route('locale.update'), [
            'locale' => 'id',
            'redirect' => '/login?from=expired',
        ])->assertRedirect('/login?from=expired')
            ->assertSessionHas('locale', 'id');

        $this->get('/login?from=expired')
            ->assertOk()
            ->assertSee('<html lang="id">', false)
            ->assertSee('Masuk ke akun Anda')
            ->assertSee('English');
    }

    public function test_locale_switch_rejects_unsupported_values_and_external_redirects(): void
    {
        $this->from('/login')->post(route('locale.update'), [
            'locale' => 'fr',
            'redirect' => '/login',
        ])->assertSessionHasErrors('locale');

        $this->post(route('locale.update'), [
            'locale' => 'id',
            'redirect' => 'https://example.com/phishing',
        ])->assertRedirect('/');
    }

    public function test_livewire_locale_switch_returns_json_without_a_page_redirect(): void
    {
        $this->postJson(route('locale.update'), [
            'locale' => 'id',
            'redirect' => '/input-dokumen?proyek=42&kriteria=7',
        ])->assertOk()
            ->assertExactJson(['locale' => 'id'])
            ->assertSessionHas('locale', 'id');
    }

    public function test_locale_refresh_clears_translated_errors_without_resetting_form_state(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'kept@example.test')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email')
            ->call('refreshLocale')
            ->assertHasNoErrors()
            ->assertSet('email', 'kept@example.test')
            ->assertSet('password', 'wrong-password');
    }

    public function test_resolver_uses_user_then_session_then_config_with_an_english_safety_fallback(): void
    {
        $resolver = app(LocaleResolver::class);
        $session = app('session.store');
        $session->put('locale', 'en');
        $user = new User;
        $user->setAttribute('locale', 'id');

        $this->assertSame('id', $resolver->resolve($user, $session));

        $user->setAttribute('locale', 'invalid');
        $this->assertSame('en', $resolver->resolve($user, $session));

        $session->forget('locale');
        config(['app.locale' => 'invalid']);
        $this->assertSame('en', $resolver->resolve(null, $session));
    }

    public function test_selected_locale_survives_logout_without_persisting_a_user_preference(): void
    {
        $user = User::query()->create([
            'name' => 'Locale User',
            'email' => 'locale@example.test',
            'password' => Hash::make('ValidPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['locale' => 'id'])
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('locale', 'id');

        $this->assertGuest();
        $this->assertArrayNotHasKey('locale', $user->getAttributes());
    }

    public function test_translation_catalogs_have_matching_keys_and_labels_do_not_mutate_codes(): void
    {
        foreach (glob(lang_path('en/*.php')) ?: [] as $englishFile) {
            $name = basename($englishFile);
            $indonesianFile = lang_path('id/'.$name);

            $this->assertFileExists($indonesianFile);

            $english = $this->flatten(require $englishFile);
            $indonesian = $this->flatten(require $indonesianFile);

            $this->assertSame(
                array_keys($english),
                array_keys($indonesian),
                "Translation keys differ for {$name}",
            );

            foreach ($english as $key => $value) {
                $this->assertSame(
                    $this->placeholders($value),
                    $this->placeholders($indonesian[$key]),
                    "Translation placeholders differ for {$name}:{$key}",
                );
            }
        }

        app()->setLocale('id');
        $this->assertSame('PIC Kriteria', LocalizedLabel::role('PIC Kriteria'));
        $this->assertSame('Dalam Peninjauan', LocalizedLabel::status('in_review'));
        $this->assertSame('Custom Machine Code', LocalizedLabel::status('custom_machine_code'));
    }

    /** @return array<string, mixed> */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($items as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flattened += $this->flatten($value, $fullKey);
            } else {
                $flattened[$fullKey] = $value;
            }
        }

        return $flattened;
    }

    /** @return list<string> */
    private function placeholders(mixed $value): array
    {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', (string) $value, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}
