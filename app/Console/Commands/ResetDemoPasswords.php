<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Resetea los passwords de los usuarios demo a un valor conocido.
 *
 * Util cuando se quiere entrar rapido al frontend sin recordar que
 * gabo usa una password y grupoprueba otra. Por defecto ambos quedan
 * con 'gabo1234'.
 *
 * Uso:
 *   php artisan dev:reset-demo-passwords
 *   php artisan dev:reset-demo-passwords --password=miclave123
 */
class ResetDemoPasswords extends Command
{
    protected $signature = 'dev:reset-demo-passwords
        {--password=gabo1234 : Password a setear en ambos usuarios demo}';

    protected $description = 'Resetea los passwords de los usuarios demo (gabo + grupoprueba) al valor indicado.';

    public function handle(): int
    {
        $password = (string) $this->option('password');
        if (strlen($password) < 8) {
            $this->error('Password debe tener al menos 8 caracteres.');

            return self::INVALID;
        }

        $emails = ['gabo@gabo.com', 'grupoprueba@grupoprueba.com'];
        $reset = 0;
        $missing = [];

        foreach ($emails as $email) {
            $u = User::where('email', $email)->first();
            if (! $u) {
                $missing[] = $email;
                continue;
            }
            $u->password = Hash::make($password);
            $u->save();
            $this->line("  - {$email}: password reseteada");
            $reset++;
        }

        if ($missing !== []) {
            $this->warn('Usuarios no encontrados (probablemente no se corrieron los seeders):');
            foreach ($missing as $email) {
                $this->line("    - {$email}");
            }
        }

        $this->info("Reseteados: {$reset} usuario(s). Password: '{$password}'.");

        return self::SUCCESS;
    }
}