<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('admin:create {email} {--name=Administrateur}')]
#[Description('Creer un compte administrateur avec saisie masquee du mot de passe')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Executez cette commande dans un terminal interactif pour saisir le mot de passe.');

            return self::FAILURE;
        }
        $data = [
            'email' => $this->argument('email'), 'name' => $this->option('name'),
            'password' => $this->secret('Mot de passe (12 caracteres minimum)'),
            'password_confirmation' => $this->secret('Confirmez le mot de passe'),
        ];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = new User($validator->safe()->only(['name', 'email', 'password']));
        $user->is_admin = true;
        $user->save();
        $this->info('Compte administrateur cree. Utilisez la connexion de votre frontend.');

        return self::SUCCESS;
    }
}
