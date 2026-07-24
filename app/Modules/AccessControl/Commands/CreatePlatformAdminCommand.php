<?php

namespace App\Modules\AccessControl\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'access:create-platform-admin
        {name : Nombre del nuevo Platform Admin}
        {email : Correo electronico (unico)}
        {--password= : Contrasea (si se omite, se genera una aleatoria de 32 chars)}';

    protected $description = 'Crea un nuevo usuario Platform Admin desde cero. Crea el User si no existe, hashea la clave, y marca is_platform_admin=true.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = Str::lower(trim((string) $this->argument('email')));
        $password = (string) ($this->option('password') ?? '');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("El email '{$email}' no es valido.");

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $existing = User::query()->where('email', $email)->first();
            if ($existing->is_platform_admin) {
                $this->info("El usuario {$email} ya era Platform Admin. Sin cambios.");

                return self::SUCCESS;
            }
            $existing->is_platform_admin = true;
            $existing->save();
            $this->info("Usuario {$email} promovido a Platform Admin.");

            return self::SUCCESS;
        }

        if ($password === '') {
            $password = Str::random(32);
            $this->line('Contrasena generada automaticamente (mostrada abajo).');
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->info('Platform Admin creado:');
        $this->line("  Nombre:  {$user->name}");
        $this->line("  Email:   {$user->email}");
        $this->line("  Contrasena inicial: {$password}");
        $this->newLine();
        $this->warn('IMPORTANTE: Guarda la contrasena en un lugar seguro. No se mostrara de nuevo.');

        return self::SUCCESS;
    }
}
