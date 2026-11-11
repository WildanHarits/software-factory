<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-super-admin
    {--name= : The super admin\'s name}
    {--email= : The super admin\'s email address}
    {--password= : The password (prompted for when left out)}')]
#[Description('Create the first super admin, or promote an existing user')]
class CreateSuperAdmin extends Command
{
    public function handle(CreateUser $createUser): int
    {
        $email = $this->option('email') ?: text(
            label: 'Email address',
            required: true,
            validate: ['email' => 'email'],
        );

        if ($existing = User::where('email', $email)->first()) {
            if ($existing->is_super_admin) {
                $this->components->info("{$email} is already a super admin.");

                return self::SUCCESS;
            }

            if ($this->input->isInteractive() && ! confirm("{$email} already exists. Make them a super admin?")) {
                return self::FAILURE;
            }

            $existing->forceFill(['is_super_admin' => true])->save();
            $this->components->info("{$email} is now a super admin.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: text(label: 'Name', required: true);
        $plainPassword = $this->option('password') ?: password(label: 'Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $createUser->handle($name, $email, $plainPassword, isSuperAdmin: true);

        $this->components->info("Super admin {$email} created. You can log in now.");

        return self::SUCCESS;
    }
}
