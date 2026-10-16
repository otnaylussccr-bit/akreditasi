<div class="account-page">
    <header class="page-heading account-heading">
        <div>
            <span class="eyebrow dark">Keamanan Akun</span>
            <h1>Ubah Password</h1>
            <p>Perbarui password untuk {{ $user->email }} dan akhiri session lain yang masih aktif.</p>
        </div>
        <span class="role-badge">{{ $user->getRoleNames()->join(', ') ?: 'Tanpa role' }}</span>
    </header>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    <section class="account-card">
        <div class="account-card-intro">
            <strong>Password yang kuat</strong>
            <p>Gunakan minimal 8 karakter yang mengandung huruf dan angka. Untuk keamanan lebih baik, gunakan password yang lebih panjang dan tidak mudah ditebak.</p>
        </div>

        <form wire:submit="updatePassword" class="account-form">
            <div x-data="{ visible: false }" class="account-field">
                <label for="current-password">Password Saat Ini</label>
                <div class="password-control">
                    <input wire:model="currentPassword" id="current-password" name="current-password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="current-password" required aria-describedby="current-password-error" @if ($errors->has('currentPassword')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle label="password saat ini" />
                </div>
                @error('currentPassword') <p id="current-password-error" class="field-error">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ visible: false }" class="account-field">
                <label for="new-password">Password Baru</label>
                <div class="password-control">
                    <input wire:model="newPassword" id="new-password" name="new-password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required aria-describedby="new-password-error" @if ($errors->has('newPassword')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle label="password baru" />
                </div>
                @error('newPassword') <p id="new-password-error" class="field-error">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ visible: false }" class="account-field">
                <label for="new-password-confirmation">Konfirmasi Password Baru</label>
                <div class="password-control">
                    <input wire:model="newPassword_confirmation" id="new-password-confirmation" name="new-password-confirmation" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required>
                    <x-password-visibility-toggle label="konfirmasi password" />
                </div>
            </div>

            <button class="primary-button account-submit" type="submit" wire:loading.attr="disabled" wire:target="updatePassword">
                <span wire:loading.remove wire:target="updatePassword">Simpan Password Baru</span>
                <span wire:loading wire:target="updatePassword">Memperbarui keamanan akun...</span>
            </button>
        </form>
    </section>
</div>
