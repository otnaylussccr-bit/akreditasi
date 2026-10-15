<main class="login-shell">
    <section class="login-intro">
        <div class="login-intro-content">
            <img
                class="login-official-banner login-official-banner-desktop"
                src="{{ asset('images/apu/logo APU - tag.png') }}"
                alt="Agung Putra University - Empowering Science Tech"
            >
            <span class="eyebrow">APU Quality Assurance</span>
            <h1>Akreditasi terarah,<br>kolaborasi terjaga.</h1>
            <p>Ruang kerja terpusat untuk pengelolaan proses akreditasi program studi.</p>
        </div>
        <div class="intro-line" aria-hidden="true"></div>
        <p class="intro-footer">Accreditation Management System</p>
    </section>

    <section class="login-panel">
        <div class="login-card">
            <div class="login-brand">
                <img
                    class="login-official-banner login-official-banner-mobile"
                    src="{{ asset('images/apu/logo APU - tag.png') }}"
                    alt="Agung Putra University - Empowering Science Tech"
                >
            </div>
            <div class="login-heading">
                <span>Selamat datang</span>
                <h2>Masuk ke akun Anda</h2>
                <p>Gunakan akun yang telah diberikan oleh administrator.</p>
            </div>

            <form wire:submit="login" class="login-form">
                <label for="email">Email</label>
                <input wire:model="email" id="email" name="email" type="email" autocomplete="email" placeholder="nama@apu.ac.id" autofocus>
                @error('email') <p class="field-error">{{ $message }}</p> @enderror

                <label for="password">Password</label>
                <input wire:model="password" id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password">
                @error('password') <p class="field-error">{{ $message }}</p> @enderror

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
