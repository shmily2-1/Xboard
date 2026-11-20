#requires -Version 5.1
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object Text.UTF8Encoding($false)
$Root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')).TrimEnd('\')
Set-Location -LiteralPath $Root
try {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw '请以管理员身份运行。' }
    if ((Test-Path -LiteralPath (Join-Path $Root '.env')) -or (Test-Path -LiteralPath (Join-Path $Root 'bootstrap\cache\config.php'))) {
        throw '检测到已有配置，为防止覆盖数据已拒绝初始化。日常使用请运行 Xboard-Manager.bat。'
    }
    $settings = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'settings.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if (!(Test-Path -LiteralPath $settings.PhpExe) -or !(Test-Path -LiteralPath (Join-Path $Root 'vendor\autoload.php'))) { throw '需要宝塔 PHP 8.2 和完整程序包（含 vendor）。' }
    $siteConf = Join-Path $settings.NginxRoot ('conf\vhost\' + $settings.SiteHost + '.conf')
    $expectedRoot = ($Root.Replace('\','/') + '/public')
    if (!(Test-Path -LiteralPath $siteConf) -or (Get-Content -LiteralPath $siteConf -Raw) -notmatch ('root\s+' + [regex]::Escape($expectedRoot) + '\s*;')) {
        throw '请先在宝塔设置网站运行目录为 /public，并按 WINDOWS-README.md 配置 Nginx，避免暴露 .env。'
    }
    $backupFull = [IO.Path]::GetFullPath($settings.BackupRoot).TrimEnd('\')
    if ($backupFull.Equals($Root, [StringComparison]::OrdinalIgnoreCase) -or $backupFull.StartsWith($Root + '\', [StringComparison]::OrdinalIgnoreCase)) { throw '备份目录必须位于网站目录外。' }
    $phpDirectory = Split-Path -Parent $settings.PhpExe
    $ini = Get-Content -LiteralPath (Join-Path $phpDirectory 'php.ini') -Raw
    $ini = $ini -replace '(?m)^disable_functions\s*=.*$', 'disable_functions ='
    $ini = $ini -replace '(?m)^extension_dir\s*=.*$', ('extension_dir = "' + ($phpDirectory.Replace('\','/') + '/ext') + '"')
    if ($ini -notmatch '(?m)^\s*extension\s*=\s*"?(?:php_)?zip(?:\.dll)?"?\s*$') { $ini += "
extension=zip
" }
    [IO.File]::WriteAllText((Join-Path $PSScriptRoot 'php-cli.ini'), $ini, (New-Object Text.UTF8Encoding($false)))
    $dbName = Read-Host '数据库名（留空使用 dorocloud_net）'
    if (!$dbName) { $dbName = 'dorocloud_net' }
    $dbUser = Read-Host '数据库用户（留空使用 dorocloud_net）'
    if (!$dbUser) { $dbUser = 'dorocloud_net' }
    $securePassword = Read-Host '数据库密码（隐藏输入，不会写入 BAT）' -AsSecureString
    $passwordPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try { $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPtr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPtr) }
    $email = Read-Host '管理员邮箱（留空使用 admin@dorocloud.net）'
    if (!$email) { $email = 'admin@dorocloud.net' }
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes); $rng.Dispose()
    $key = 'base64:' + [Convert]::ToBase64String($bytes)
    $content = Get-Content -LiteralPath (Join-Path $Root '.env.windows.example') -Raw -Encoding UTF8
    $values = @{APP_KEY=$key; DB_DATABASE=$dbName; DB_USERNAME=$dbUser; DB_PASSWORD=$dbPassword; APP_URL=('http://' + $settings.SiteHost); WINDOWS_BACKUP_DIR=$settings.BackupRoot.Replace('\','/')}
    foreach ($name in $values.Keys) {
        $value = [string]$values[$name]
        if ($value.Contains([char]10) -or $value.Contains([char]13)) { throw '配置不能包含换行。' }
        $escaped = $value.Replace('\','\\').Replace('"','\"').Replace('$','\$')
        $line = $name + '="' + $escaped + '"'
        $content = [regex]::Replace($content, ('(?m)^' + [regex]::Escape($name) + '=.*$'), [Text.RegularExpressions.MatchEvaluator]{ param($match) $line })
    }
    # Source and credentials are readable only by this administrator and SYSTEM.
    foreach ($dir in @($Root,$settings.BackupRoot)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        $acl = New-Object Security.AccessControl.DirectorySecurity
        $acl.SetAccessRuleProtection($true,$false)
        foreach ($sidText in @('S-1-5-18','S-1-5-32-544',$identity.User.Value) | Select-Object -Unique) {
            $sid = New-Object Security.Principal.SecurityIdentifier($sidText)
            $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')))
        }
        Set-Acl -LiteralPath $dir -AclObject $acl
    }
    [IO.File]::WriteAllText((Join-Path $Root '.env'), $content, (New-Object Text.UTF8Encoding($false)))
    $dbPassword = $null; $content = $null; $values = $null
    $credentials = Join-Path $settings.BackupRoot ('initial-admin-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt')
    $php = $settings.PhpExe
    $cliIni = Join-Path $PSScriptRoot 'php-cli.ini'
    & $php -c $cliIni (Join-Path $Root 'artisan') package:discover --no-interaction
    if ($LASTEXITCODE -ne 0) { throw '框架初始化失败。保留配置供排查，不会删除数据。' }
    & $php -c $cliIni (Join-Path $Root 'artisan') xboard:windows-install ('--email=' + $email) ('--credentials=' + $credentials)
    if ($LASTEXITCODE -ne 0) { throw '初始化失败。仅允许空数据库，保留配置供排查；不要清空现有数据库。' }
    & $php -c $cliIni (Join-Path $Root 'artisan') config:cache
    if ($LASTEXITCODE -ne 0) { throw '配置缓存失败。' }
    $ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    & $ps -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'manage.ps1') -Action EnableStartup
    if ($LASTEXITCODE -ne 0) { throw '开机自启配置失败。' }
    & $ps -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'manage.ps1') -Action Start
    if ($LASTEXITCODE -ne 0) { throw '后台启动失败。' }
    Write-Host ('初始化完成，管理员资料已保存到：' + $credentials) -ForegroundColor Green
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
