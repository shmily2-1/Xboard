#requires -Version 5.1
param(
    [ValidateSet('Menu','Start','Stop','Restart','Status','Backup','Cache','Test','Logs','EnableStartup','DisableStartup','Admin','Supervise')]
    [string]$Action = 'Menu'
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$Root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')).TrimEnd('\')
$Settings = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'settings.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$Php = $Settings.PhpExe
$Ini = Join-Path $PSScriptRoot 'php-cli.ini'
$Artisan = Join-Path $Root 'artisan'
$Runtime = Join-Path $Root 'storage\app\windows'
$Logs = Join-Path $Root 'storage\logs\windows'
$StateFile = Join-Path $Runtime 'supervisor.json'
$StopFile = Join-Path $Runtime 'stop.flag'
$ManagerPath = Join-Path $PSScriptRoot 'manage.ps1'
$PowerShellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
foreach ($dir in @($Runtime, $Logs)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
if (!(Test-Path -LiteralPath $Php)) { throw "PHP 不存在，请修改 windows\settings.json：$Php" }
# Propagate the private CLI configuration to Artisan's scheduler subprocesses.
$env:PHPRC = $Ini
$env:PHP_INI_SCAN_DIR = ''
$env:PATH = (Split-Path -Parent $Php) + ';' + $env:PATH
Set-Location -LiteralPath $Root

function Get-Epoch { return [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() }
function Require-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw '请右键 BAT，选择“以管理员身份运行”。不会停止宝塔共享服务。'
    }
}
function Protect-PrivateDirectory([string]$Directory) {
    $full = [IO.Path]::GetFullPath($Directory).TrimEnd('\')
    if ($full.Equals($Root, [StringComparison]::OrdinalIgnoreCase) -or $full.StartsWith($Root + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw '备份目录不得放在网站目录中。'
    }
    New-Item -ItemType Directory -Path $full -Force | Out-Null
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($sidText in @('S-1-5-18','S-1-5-32-544', [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) | Select-Object -Unique) {
        $sid = New-Object Security.Principal.SecurityIdentifier($sidText)
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $full -AclObject $acl
}
function Invoke-Artisan([string[]]$Parameters) {
    & $Php -c $Ini $Artisan @Parameters
    if ($LASTEXITCODE -ne 0) { throw "操作失败，退出代码 $LASTEXITCODE；请查看 storage\logs。" }
}
function Read-State {
    if (Test-Path -LiteralPath $StateFile) {
        try { return (Get-Content -LiteralPath $StateFile -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { return $null }
    }
    return $null
}
function Test-OwnedProcess($ProcessId, $Ticks, [string]$Kind) {
    if (!$ProcessId) { return $false }
    try {
        $process = Get-Process -Id ([int]$ProcessId) -ErrorAction Stop
        if ($Ticks -and $process.StartTime.ToUniversalTime().Ticks -ne [long]$Ticks) { return $false }
        $wmi = Get-CimInstance Win32_Process -Filter "ProcessId=$([int]$ProcessId)"
        if (!$wmi.CommandLine) { return $false }
        if ($Kind -eq 'supervisor') {
            return ($wmi.Name -ieq 'powershell.exe' -and $wmi.CommandLine.Contains($ManagerPath) -and $wmi.CommandLine -match 'Supervise')
        }
        return ($wmi.Name -ieq 'php.exe' -and $wmi.CommandLine.Contains($Artisan))
    } catch { return $false }
}
function Stop-OwnedProcess($ProcessId, $Ticks, [string]$Kind = 'php') {
    if (Test-OwnedProcess $ProcessId $Ticks $Kind) {
        # Only this site's verified process tree; never kill all php.exe or shared services.
        & "$env:SystemRoot\System32\taskkill.exe" /PID ([int]$ProcessId) /T /F 2>&1 | Out-Null
    }
}
function Write-RuntimeLog([string]$Message) {
    $log = Join-Path $Logs 'supervisor.log'
    if ((Test-Path -LiteralPath $log) -and (Get-Item -LiteralPath $log).Length -gt 5MB) {
        Copy-Item -LiteralPath $log -Destination ($log + '.previous') -Force
        [IO.File]::WriteAllText($log, '')
    }
    [IO.File]::AppendAllText($log, ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) + [Environment]::NewLine, (New-Object Text.UTF8Encoding($false)))
}
function Write-State($State) {
    $json = $State | ConvertTo-Json -Depth 8
    $tmp = $StateFile + '.tmp'
    [IO.File]::WriteAllText($tmp, $json, (New-Object Text.UTF8Encoding($false)))
    if (Test-Path -LiteralPath $StateFile) {
        [IO.File]::Replace($tmp, $StateFile, ($StateFile + '.previous'))
    } else {
        [IO.File]::Move($tmp, $StateFile)
    }
}
function Start-PhpChild([string]$Name, [string[]]$Parameters) {
    $out = Join-Path $Logs ($Name + '.log')
    $err = Join-Path $Logs ($Name + '.error.log')
    foreach ($file in @($out, $err)) {
        if ((Test-Path -LiteralPath $file) -and (Get-Item -LiteralPath $file).Length -gt 0) {
            Copy-Item -LiteralPath $file -Destination ($file + '.previous') -Force
        }
    }
    $arguments = @('-c', ('"{0}"' -f $Ini), ('"{0}"' -f $Artisan)) + $Parameters
    $child = Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $Root -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru
    $null = $child.Handle # Keep the native handle so ExitCode is available after exit in Windows PowerShell.
    return $child
}
function Remove-StaleWorkerHeartbeat([string]$Path) {
    # A reader/antivirus can hold the old heartbeat briefly on Windows.
    # This is disposable metadata: never stop all queues if cleanup fails.
    try {
        if (Test-Path -LiteralPath $Path) {
            Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
        }
    } catch {
        Write-RuntimeLog ('Heartbeat cleanup skipped (file busy): ' + $Path)
    }
}
function Invoke-Supervisor {
    Require-Administrator
    $lock = $null
    try { $lock = [IO.File]::Open((Join-Path $Runtime 'supervisor.lock'), 'OpenOrCreate', 'ReadWrite', 'None') }
    catch { Write-Output '已有后台管理进程，本次不重复启动。'; return }
    $roles = @(
        @{ Name='data'; Queue='traffic_fetch,stat,user_alive_sync'; Process=$null; Ticks=0; LastStart=0 },
        @{ Name='business'; Queue='default,order_handle'; Process=$null; Ticks=0; LastStart=0 },
        @{ Name='notification'; Queue='send_email,send_telegram,send_email_mass,node_sync'; Process=$null; Ticks=0; LastStart=0 }
    )
    $scheduler = $null
    $schedulerTicks = 0
    $scheduleStart = 0
    $lastMinute = -1
    $lastScheduleExit = $null
    $selfTicks = (Get-Process -Id $PID).StartTime.ToUniversalTime().Ticks
    try {
        # Recover this site's orphaned processes if a previous supervisor crashed.
        $old = Read-State
        if ($old) {
            foreach ($worker in @($old.workers)) { if ($worker) { Stop-OwnedProcess $worker.pid $worker.start_ticks } }
            if ($old.scheduler_pid) { Stop-OwnedProcess $old.scheduler_pid $old.scheduler_start_ticks }
        }
        if (Test-Path -LiteralPath $StopFile) { return }
        Write-RuntimeLog "Supervisor started pid=$PID"
        while (!(Test-Path -LiteralPath $StopFile)) {
            $now = Get-Epoch
            $states = @()
            foreach ($role in $roles) {
                if ($role.Process) {
                    $role.Process.Refresh()
                    if (!$role.Process.HasExited) {
                        $beatPath = Join-Path $Runtime ('worker-' + $role.Process.Id + '.json')
                        $beat = $null
                        if (Test-Path -LiteralPath $beatPath) {
                            try { $beat = Get-Content -LiteralPath $beatPath -Raw -Encoding UTF8 | ConvertFrom-Json } catch {}
                        }
                        $expired = $false
                        if ($beat -and $beat.state -eq 'busy') {
                            $expired = (($now - [double]$beat.started) -gt [int]$beat.deadline_seconds)
                        } elseif ($beat) {
                            $expired = (($now - [long]$beat.timestamp) -gt 90)
                        } else {
                            $expired = (($now - $role.LastStart) -gt 60)
                        }
                        if ($expired) {
                            Write-RuntimeLog ('Watchdog timeout: ' + $role.Name + ' pid=' + $role.Process.Id)
                            Stop-OwnedProcess $role.Process.Id $role.Ticks
                            $role.Process.WaitForExit(5000) | Out-Null
                        }
                    }
                }
                if ((!$role.Process -or $role.Process.HasExited) -and ($now - $role.LastStart -ge 5)) {
                    if ($role.Process) {
                        $oldBeat = Join-Path $Runtime ('worker-' + $role.Process.Id + '.json')
                        Remove-StaleWorkerHeartbeat $oldBeat
                    }
                    $role.Process = Start-PhpChild $role.Name @('queue:work','redis',('--queue=' + $role.Queue),'--sleep=2','--tries=3','--backoff=3','--timeout=0','--force','--memory=256','--max-time=3600','--max-jobs=1000','--no-interaction')
                    $role.Ticks = $role.Process.StartTime.ToUniversalTime().Ticks
                    $role.LastStart = $now
                    Write-RuntimeLog ('Worker started: ' + $role.Name + ' pid=' + $role.Process.Id)
                }
                $healthy = $false
                if ($role.Process -and !$role.Process.HasExited) {
                    $beatPath = Join-Path $Runtime ('worker-' + $role.Process.Id + '.json')
                    if (Test-Path -LiteralPath $beatPath) {
                        try {
                            $beat = Get-Content -LiteralPath $beatPath -Raw -Encoding UTF8 | ConvertFrom-Json
                            $healthy = (($beat.state -eq 'busy' -and ($now - [double]$beat.started) -le [int]$beat.deadline_seconds) -or ($now - [long]$beat.timestamp -le 15))
                        } catch {}
                    }
                }
                $states += @{name=$role.Name; queue=$role.Queue; pid=$(if ($role.Process) {$role.Process.Id} else {0}); start_ticks=$role.Ticks; healthy=$healthy}
            }
            if ($scheduler) {
                $scheduler.Refresh()
                if ($scheduler.HasExited) {
                    $scheduler.WaitForExit()
                    $lastScheduleExit = $scheduler.ExitCode
                    if ($lastScheduleExit -ne 0) { Write-RuntimeLog "Scheduler exit code=$lastScheduleExit" }
                    $scheduler.Dispose()
                    $scheduler = $null
                } elseif ($now - $scheduleStart -gt 300) {
                    Write-RuntimeLog 'Scheduler timeout after 300 seconds'
                    Stop-OwnedProcess $scheduler.Id $schedulerTicks
                }
            }
            $minute = [Math]::Floor($now / 60)
            if (!$scheduler -and $minute -ne $lastMinute) {
                $scheduler = Start-PhpChild 'scheduler' @('schedule:run','--no-interaction')
                $schedulerTicks = $scheduler.StartTime.ToUniversalTime().Ticks
                $scheduleStart = $now
                $lastMinute = $minute
            }
            Write-State @{running=$true; timestamp=$now; pid=$PID; start_ticks=$selfTicks; workers=$states; scheduler_pid=$(if($scheduler){$scheduler.Id}else{0}); scheduler_start_ticks=$schedulerTicks; last_schedule_exit=$lastScheduleExit}
            Start-Sleep -Seconds 2
        }
    } finally {
        $manualStop = Test-Path -LiteralPath $StopFile
        [IO.File]::WriteAllText($StopFile, 'stop')
        $deadline = (Get-Date).AddSeconds(130)
        do {
            $alive = @($roles | Where-Object { $_.Process -and !$_.Process.HasExited })
            if ($alive.Count -eq 0 -and (!$scheduler -or $scheduler.HasExited)) { break }
            Start-Sleep -Seconds 1
        } while ((Get-Date) -lt $deadline)
        foreach ($role in $roles) { if ($role.Process) { Stop-OwnedProcess $role.Process.Id $role.Ticks } }
        if ($scheduler) { Stop-OwnedProcess $scheduler.Id $schedulerTicks }
        Write-State @{running=$false; timestamp=(Get-Epoch); pid=$PID; start_ticks=$selfTicks; workers=@(); scheduler_pid=0; scheduler_start_ticks=0; last_schedule_exit=$lastScheduleExit}
        Write-RuntimeLog "Supervisor stopped pid=$PID"
        if (!$manualStop -and (Test-Path -LiteralPath $StopFile)) { Remove-Item -LiteralPath $StopFile -Force }
        $lock.Dispose()
    }
}
function Start-Xboard {
    Require-Administrator
    foreach ($service in @('mysql','redis','nginx')) {
        if ((Get-Service -Name $service).Status -ne 'Running') { Start-Service -Name $service }
    }
    Invoke-Artisan -Parameters @('xboard:windows-health')
    $state = Read-State
    $running = $state -and (Test-OwnedProcess $state.pid $state.start_ticks 'supervisor')
    if ($running -and (Test-Path -LiteralPath $StopFile)) { throw '后台正在退出，请稍后再启动。' }
    if (!$running) {
        if (Test-Path -LiteralPath $StopFile) { Remove-Item -LiteralPath $StopFile -Force }
        $task = Get-ScheduledTask -TaskName $Settings.TaskName -ErrorAction SilentlyContinue
        if ($task) {
            if ($task.State -eq 'Disabled') { Enable-ScheduledTask -TaskName $Settings.TaskName | Out-Null }
            Start-ScheduledTask -TaskName $Settings.TaskName
        } else {
            Start-Process -FilePath $PowerShellExe -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',('"{0}"' -f $ManagerPath),'-Action','Supervise') -WindowStyle Hidden -WorkingDirectory $Root | Out-Null
        }
    }
    $deadline = (Get-Date).AddSeconds(45)
    do {
        Start-Sleep -Seconds 1
        $state = Read-State
        if ($state -and $state.running -and ((Get-Epoch) - $state.timestamp -lt 20) -and @($state.workers | Where-Object {$_.healthy}).Count -eq 3) { break }
    } while ((Get-Date) -lt $deadline)
    if (!$state -or !$state.running -or ((Get-Epoch) - $state.timestamp -ge 20) -or @($state.workers | Where-Object {$_.healthy}).Count -ne 3) {
        throw "后台未就绪，请查看 $Logs。网站保持原来的维护状态。"
    }
    Invoke-Artisan -Parameters @('up')
    Invoke-Artisan -Parameters @('schedule:run','--no-interaction')
    Invoke-Artisan -Parameters @('xboard:windows-health','--runtime')
    Write-Host '网站与 3 组后台队列已启动。宝塔共享服务未被重启。' -ForegroundColor Green
}
function Stop-Xboard {
    Require-Administrator
    Invoke-Artisan -Parameters @('down','--retry=60')
    [IO.File]::WriteAllText($StopFile, 'stop')
    Invoke-Artisan -Parameters @('queue:restart')
    $deadline = (Get-Date).AddSeconds(150)
    do {
        $state = Read-State
        if (!$state -or !(Test-OwnedProcess $state.pid $state.start_ticks 'supervisor')) { break }
        Start-Sleep -Seconds 2
    } while ((Get-Date) -lt $deadline)
    if ($state -and (Test-OwnedProcess $state.pid $state.start_ticks 'supervisor')) {
        throw '优雅停止超时；未强制终止未知进程，请查看日志。'
    }
    Write-Host '网站进入维护模式，后台任务已停止。Nginx/MySQL/Redis 保持运行。' -ForegroundColor Yellow
}
function Set-Startup([bool]$Enabled) {
    Require-Administrator
    if (!$Enabled) {
        $task = Get-ScheduledTask -TaskName $Settings.TaskName -ErrorAction SilentlyContinue
        if ($task) { Unregister-ScheduledTask -TaskName $Settings.TaskName -Confirm:$false }
        Write-Host '已取消开机自启；当前后台进程不受影响。'
        return
    }
    $actionArgs = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $ManagerPath + '" -Action Supervise'
    $taskAction = New-ScheduledTaskAction -Execute $PowerShellExe -Argument $actionArgs -WorkingDirectory $Root
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $trigger.Delay = 'PT30S'
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $options = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $Settings.TaskName -Action $taskAction -Trigger $trigger -Principal $principal -Settings $options -Description 'Xboard Windows native queues and minute scheduler. Respects maintenance stop.flag.' -Force | Out-Null
    Write-Host '已配置开机自启；手动停止后的维护状态会保留，需再次启动后恢复。'
}
function Invoke-Action([string]$Selected) {
    switch ($Selected) {
        'Start' { Start-Xboard }
        'Stop' { Stop-Xboard }
        'Restart' { Stop-Xboard; Start-Xboard }
        'Status' {
            Write-Host ('站点目录：' + $Root)
            Write-Host ('备份目录：' + $Settings.BackupRoot)
            Get-Service -Name nginx,mysql,redis | Format-Table Name,Status
            $task = Get-ScheduledTask -TaskName $Settings.TaskName -ErrorAction SilentlyContinue
            Write-Host ('开机自启：' + [bool]$task)
            Invoke-Artisan -Parameters @('xboard:windows-health','--runtime')
            $state = Read-State
            if ($state) { $state.workers | Format-Table name,pid,healthy,queue -AutoSize }
        }
        'Backup' { Require-Administrator; Protect-PrivateDirectory $Settings.BackupRoot; Invoke-Artisan -Parameters @('xboard:windows-backup') }
        'Cache' {
            Require-Administrator
            Invoke-Artisan -Parameters @('config:clear')
            Invoke-Artisan -Parameters @('view:clear')
            Invoke-Artisan -Parameters @('config:cache')
            Invoke-Artisan -Parameters @('queue:restart')
            Write-Host '配置与模板缓存已重建；没有执行 Redis FLUSHDB/FLUSHALL。'
        }
        'Test' { Invoke-Artisan -Parameters @('xboard:windows-test') }
        'Logs' {
            foreach ($file in @((Join-Path $Logs 'supervisor.log'), (Join-Path $Logs 'scheduler.error.log'), (Join-Path $Logs 'data.error.log'), (Join-Path $Logs 'business.error.log'), (Join-Path $Logs 'notification.error.log'))) {
                if (Test-Path -LiteralPath $file) { Write-Host "--- $file ---"; Get-Content -LiteralPath $file -Tail 25 -Encoding UTF8 }
            }
        }
        'EnableStartup' { Set-Startup $true }
        'DisableStartup' { Set-Startup $false }
        'Admin' { Require-Administrator; Invoke-Artisan -Parameters @('xboard:windows-admin') }
        'Supervise' { Invoke-Supervisor }
    }
}
try {
    if ($Action -ne 'Menu') { Invoke-Action $Action; exit 0 }
    while ($true) {
        Write-Host "
====== Xboard Windows 快捷管理 ======" -ForegroundColor Cyan
        Write-Host '1. 启动网站和后台    2. 停止（维护模式）    3. 重启'
        Write-Host '4. 检查状态          5. 备份数据库与网站    6. 重建配置缓存'
        Write-Host '7. 检测全部队列      8. 查看后台日志        9. 启用开机自启'
        Write-Host '10. 取消开机自启     11. 重设管理员密码     0. 退出'
        $choice = Read-Host '请选择'
        if ($choice -eq '0') { break }
        $map = @{'1'='Start';'2'='Stop';'3'='Restart';'4'='Status';'5'='Backup';'6'='Cache';'7'='Test';'8'='Logs';'9'='EnableStartup';'10'='DisableStartup';'11'='Admin'}
        if ($map.ContainsKey($choice)) {
            try { Invoke-Action $map[$choice] } catch { Write-Host $_.Exception.Message -ForegroundColor Red }
            Read-Host '按回车继续' | Out-Null
        }
    }
} catch {
    Write-Host ('错误：' + $_.Exception.Message) -ForegroundColor Red
    if ($Action -eq 'Supervise') { Write-RuntimeLog $_.Exception.ToString() }
    exit 1
}
