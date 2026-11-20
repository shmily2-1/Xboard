#requires -Version 5.1
param([ValidateSet('Menu','Start','Stop','Status','Supervise','EnableStartup','DisableStartup')][string]$Action='Menu')
$ErrorActionPreference='Stop'
[Console]::OutputEncoding=New-Object Text.UTF8Encoding($false)
$Root=$PSScriptRoot
$Node=Join-Path $Root 'runtime\node.exe'
$Server=Join-Path $Root 'server.mjs'
$Manager=Join-Path $Root 'manage-cap.ps1'
$TaskName='Xboard-dorocloud.net-Cap'
$State=Join-Path $Root 'state'
$Logs=Join-Path $Root 'logs'
$StopFlag=Join-Path $State 'stop.flag'
$StateFile=Join-Path $State 'supervisor.json'
$Shell=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
foreach($dir in @($State,$Logs)){New-Item -ItemType Directory -Path $dir -Force|Out-Null}
function Read-State {if(Test-Path -LiteralPath $StateFile){try{return Get-Content -LiteralPath $StateFile -Raw -Encoding UTF8|ConvertFrom-Json}catch{}};return $null}
function Is-Owned($id,$ticks,$kind){
    if(!$id -or !$ticks){return $false}
    try{$p=Get-Process -Id $id -ErrorAction Stop;if($p.StartTime.ToUniversalTime().Ticks -ne [long]$ticks){return $false}
        $w=Get-CimInstance Win32_Process -Filter ('ProcessId='+[int]$id)
        if($kind -eq 'node'){return ($p.Path -ieq $Node -and $w.CommandLine.Contains($Server))}
        return ($p.ProcessName -eq 'powershell' -and $w.CommandLine.Contains($Manager) -and $w.CommandLine.Contains('Supervise'))
    }catch{return $false}
}
function Read-Health {
    try{$r=Invoke-RestMethod -Uri 'http://127.0.0.1:3010/health' -TimeoutSec 2;return ($r.status -eq 'ok' -and $r.service -eq 'xboard-cap')}catch{return $false}
}
function Save-State($value){$tmp=$StateFile+'.'+$PID+'.tmp';[IO.File]::WriteAllText($tmp,($value|ConvertTo-Json -Depth 4),(New-Object Text.UTF8Encoding($false)));Move-Item -LiteralPath $tmp -Destination $StateFile -Force}
function Start-Cap {
    if(!(Test-Path -LiteralPath $Node)){throw 'Cap runtime is missing; do not modify existing PHP/nginx services.'}
    $s=Read-State
    if($s -and (Is-Owned $s.supervisorPid $s.supervisorTicks 'supervisor')){Write-Host 'Cap supervisor is already running.';return}
    if(Test-Path -LiteralPath $StopFlag){Remove-Item -LiteralPath $StopFlag -Force}
    $task=Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if($task){Start-ScheduledTask -TaskName $TaskName}else{
        Start-Process -FilePath $Shell -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',('"'+$Manager+'"'),'-Action','Supervise') -WorkingDirectory $Root -WindowStyle Hidden|Out-Null
    }
    for($i=0;$i -lt 15;$i++){Start-Sleep -Seconds 1;if(Read-Health){Write-Host 'Cap ready on 127.0.0.1:3010. CAPTCHA setting was NOT enabled.';return}}
    throw 'Cap is not ready. Check windows\cap\logs; no existing project service was restarted.'
}
function Stop-Cap {
    [IO.File]::WriteAllText($StopFlag,(Get-Date -Format o))
    $s=Read-State
    for($i=0;$i -lt 12;$i++){if(!$s -or !(Is-Owned $s.supervisorPid $s.supervisorTicks 'supervisor')){break};Start-Sleep -Seconds 1;$s=Read-State}
    if($s){if(Is-Owned $s.nodePid $s.nodeTicks 'node'){Stop-Process -Id $s.nodePid -Force};if(Is-Owned $s.supervisorPid $s.supervisorTicks 'supervisor'){Stop-Process -Id $s.supervisorPid -Force}}
    Write-Host 'Only this Cap adapter has been stopped. PHP/nginx/MySQL/Redis were not touched.'
}
function Show-Status {
    $s=Read-State
    [PSCustomObject]@{Service='Xboard Cap';Loopback='127.0.0.1:3010';Healthy=(Read-Health);SupervisorRunning=($s -and (Is-Owned $s.supervisorPid $s.supervisorTicks 'supervisor'));MaintenanceStop=(Test-Path -LiteralPath $StopFlag);StartupTask=[bool](Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)}|Format-List
    Write-Host 'This command never changes the Xboard CAPTCHA switch.'
}
function Set-Startup($enabled){
    if(!$enabled){Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue;Write-Host 'Only Cap startup task removed.';return}
    $args='-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "'+$Manager+'" -Action Supervise'
    $a=New-ScheduledTaskAction -Execute $Shell -Argument $args -WorkingDirectory $Root
    $t=New-ScheduledTaskTrigger -AtStartup;$t.Delay='PT30S'
    $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $o=New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $a -Trigger $t -Principal $p -Settings $o -Description 'Loopback Cap adapter only. Does not control nginx/PHP/MySQL/Redis. Respects stop.flag.' -Force|Out-Null
    Write-Host 'Cap startup task registered; CAPTCHA remains unchanged.'
}
function Invoke-Supervise {
    $mutex=New-Object Threading.Mutex($false,'Global\XboardDorocloudCapSupervisor')
    if(!$mutex.WaitOne(0)){return}
    $proc=$null
    try{
        if(Test-Path -LiteralPath $StopFlag){return}
        $self=Get-Process -Id $PID
        $crashes=0
        while(!(Test-Path -LiteralPath $StopFlag)){
            if(!$proc -or $proc.HasExited){
                if($proc){$crashes++;Start-Sleep -Seconds ([Math]::Min(30,2+$crashes))}
                $other=Get-NetTCPConnection -State Listen -LocalPort 3010 -ErrorAction SilentlyContinue
                if($other){throw 'Port 3010 is occupied. Refusing to terminate any unrelated process.'}
                $proc=Start-Process -FilePath $Node -ArgumentList @(('"'+$Server+'"')) -WorkingDirectory $Root -WindowStyle Hidden -RedirectStandardOutput (Join-Path $Logs 'stdout.log') -RedirectStandardError (Join-Path $Logs 'stderr.log') -PassThru
                Start-Sleep -Milliseconds 500
            }
            $ticks=0;try{$ticks=$proc.StartTime.ToUniversalTime().Ticks}catch{}
            Save-State @{supervisorPid=$PID;supervisorTicks=$self.StartTime.ToUniversalTime().Ticks;nodePid=$proc.Id;nodeTicks=$ticks;healthy=(Read-Health);time=(Get-Date -Format o);restarts=$crashes}
            Start-Sleep -Seconds 3
        }
    }finally{
        if($proc){$ticks=0;try{$ticks=$proc.StartTime.ToUniversalTime().Ticks}catch{};if(Is-Owned $proc.Id $ticks 'node'){Stop-Process -Id $proc.Id -Force}}
        $mutex.ReleaseMutex();$mutex.Dispose()
    }
}
function Run-Action($name){switch($name){'Start'{Start-Cap};'Stop'{Stop-Cap};'Status'{Show-Status};'EnableStartup'{Set-Startup $true};'DisableStartup'{Set-Startup $false};'Supervise'{Invoke-Supervise}}}
try {
    if($Action -ne 'Menu'){Run-Action $Action;exit 0}
    while($true){Write-Host "\nCap manager (isolated from the running project)\n1 Start\n2 Stop\n3 Status\n4 Enable startup (admin)\n5 Disable startup (admin)\n0 Exit";switch(Read-Host 'Select'){'1'{Start-Cap};'2'{Stop-Cap};'3'{Show-Status};'4'{Set-Startup $true};'5'{Set-Startup $false};'0'{exit 0}}}
}catch{Write-Error $_.Exception.Message;exit 1}
