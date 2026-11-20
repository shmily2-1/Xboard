<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Plugin\PluginManager;
use App\Services\ThemeService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WindowsInstall extends Command
{
    protected $signature = 'xboard:windows-install {--email=admin@dorocloud.net} {--credentials=}';
    protected $description = 'Install the Windows port into an empty, preconfigured database';

    public function handle(): int
    {
        if (!config('windows.enabled') || !filter_var($this->option('email'), FILTER_VALIDATE_EMAIL)) {
            $this->error('Windows and a valid administrator email are required.');
            return 1;
        }
        $credentials = $this->option('credentials');
        if (!$credentials || !is_dir(dirname($credentials)) || file_exists($credentials)) {
            $this->error('Provide a new credential file path in an existing PRIVATE directory.');
            return 1;
        }
        if (env('INSTALLED', false) || count(DB::select('SHOW TABLES')) !== 0) {
            $this->error('Refusing installation: the database is not empty or INSTALLED=true. No data changed.');
            return 1;
        }
        if ($this->call('migrate', ['--force' => true]) !== 0) {
            return 1;
        }
        $password = bin2hex(random_bytes(16));
        $securePath = 'admin-' . bin2hex(random_bytes(12));
        $user = new User();
        $user->email = $this->option('email');
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        $user->saveOrFail();
        admin_setting([
            'app_name' => 'DoroCloud', 'app_url' => config('app.url'),
            'secure_path' => $securePath, 'frontend_theme' => 'Xboard',
        ]);
        PluginManager::installDefaultPlugins();
        $themes = app(ThemeService::class);
        $themes->getConfig('Xboard');
        $themes->switch('Xboard');
        $env = file_get_contents(base_path('.env'));
        $env = preg_replace('/^INSTALLED=.*$/m', 'INSTALLED=true', $env);
        file_put_contents(base_path('.env'), $env, LOCK_EX);
        $text = "Xboard Windows 本机初始管理员资料\r\n\r\n";
        $text .= '后台地址：' . rtrim(config('app.url'), '/') . '/' . $securePath . "\r\n";
        $text .= '管理员邮箱：' . $user->email . "\r\n";
        $text .= '初始密码：' . $password . "\r\n\r\n";
        $text .= "请先配置 HTTPS，再通过公网登录。首次登录后修改密码，并安全删除本文件。\r\n此邮箱只是登录名，尚未配置 SMTP。\r\n";
        if (file_put_contents($credentials, "\xEF\xBB\xBF" . $text, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write private credentials; use xboard:windows-admin to reset.');
        }
        $this->info('Installation completed. Credentials saved privately; no password printed.');
        return 0;
    }
}
