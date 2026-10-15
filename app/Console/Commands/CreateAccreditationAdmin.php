<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateAccreditationAdmin extends Command
{
    protected $signature = 'accreditation:create-admin';

    protected $description = 'Create an accreditation Super Admin account interactively';

    public function handle(): int
    {
        $role = Role::query()
            ->where('name', 'Super Admin')
            ->where('guard_name', 'web')
            ->first();

        if (! $role) {
            $this->error('Role Super Admin belum tersedia. Jalankan AccreditationRolePermissionSeeder terlebih dahulu.');

            return self::FAILURE;
        }

        $data = [
            'name' => trim((string) $this->ask('Name')),
            'email' => strtolower(trim((string) $this->ask('Email'))),
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Password Confirmation'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->assignRole($role);

        $this->info('Super Admin berhasil dibuat.');

        return self::SUCCESS;
    }
}
