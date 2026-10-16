<main class="login-shell">
    <section class="login-intro">
        <div class="login-intro-content">
            <span class="eyebrow">APU Quality Assurance</span>
            <h1>Akreditasi terarah,<br>kolaborasi terjaga.</h1>
            <p>Ruang kerja terpusat untuk pengelolaan proses akreditasi program studi.</p>
        </div>
        <div class="intro-line" aria-hidden="true"></div>
        <p class="intro-footer">Accreditation Management System</p>
    </section>

    <section class="login-panel">
        <div class="login-card">
            <div class="login-heading">
                <span>Selamat datang</span>
                <h2>Masuk ke akun Anda</h2>
                <p>Gunakan akun yang telah diberikan oleh administrator.</p>
            </div>

            @if (session('status'))
                <div class="login-status" role="status">{{ session('status') }}</div>
            @endif

            <form wire:submit="login" class="login-form">
                <label for="email">Email</label>
                <input wire:model="email" id="email" name="email" type="email" autocomplete="email" placeholder="nama@apu.ac.id" required autofocus aria-describedby="email-error" @if ($errors->has('email')) aria-invalid="true" @endif>
                @error('email') <p id="email-error" class="field-error">{{ $message }}</p> @enderror

                <label for="password">Password</label>
                <div x-data="{ visible: false }" class="password-control login-password-control">
                    <input wire:model="password" id="password" name="password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="current-password" placeholder="Masukkan password" required aria-describedby="password-error" @if ($errors->has('password')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle />
                </div>
                @error('password') <p id="password-error" class="field-error">{{ $message }}</p> @enderror

                <label class="remember-row" for="remember">
                    <input wire:model="remember" id="remember" type="checkbox">
                    <span>Ingat saya pada perangkat ini</span>
                </label>

                <button class="primary-button" type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="login">Masuk</span>
                    <span wire:loading wire:target="login">Memverifikasi...</span>
                </button>
            </form>

            <p class="login-note">Tidak tersedia registrasi publik. Hubungi administrator untuk akses.</p>
        </div>
    </section>
</main>
