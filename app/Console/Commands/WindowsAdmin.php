<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WindowsAdmin extends Command
{
    protected $signature = 'xboard:windows-admin {--email=}';
    protected $description = 'Reset an existing administrator password using hidden input';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Existing administrator email', 'admin@dorocloud.net');
        $user = User::where('email', $email)->where('is_admin', 1)->first();
        if (!$user) {
            $this->error('Administrator not found; no account was created.');
            return 1;
        }
        $password = $this->secret('New password (12+ characters)');
        if (!$password || strlen($password) < 12 || $this->secret('Confirm password') !== $password) {
            $this->error('Password too short or confirmation does not match.');
            return 1;
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->saveOrFail();
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
        $this->info('Administrator password changed. Remove obsolete initial credential files.');
        return 0;
    }
}
