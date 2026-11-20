#requires -Version 5.1
param([ValidateSet('Menu','Start','Stop','Restart','Status','Test','EnableStartup','DisableStartup','Supervise')][string]$Action='Menu')
$ErrorActionPreference='Stop'
[Console]::OutputEncoding=New-Object Text.UTF8Encoding($false)
$Root=$PSScriptRoot
$Nginx=Join-Path $Root 'runtime\nginx.exe'
$Prefix=Join-Path $Root 'nginx'
$PrefixArg=$Prefix.Replace('\','/')+'/'
$ConfigArg='conf/nginx.conf'
$Manager=Join-Path $Root 'manage-api.ps1'
$StateDir=Join-Path $Root 'state'
$StateFile=Join-Path $StateDir 'supervisor.json'
$StopFile=Join-Path $StateDir 'stop.flag'
$LogDir=Join-Path $Root 'logs'
$TaskName='Xboard-dorocloud.net-API8380'
$Shell=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
foreach($dir in @($StateDir,$LogDir,(Join-Path $Prefix 'logs'),(Join-Path $Prefix 'temp'))){New-Item -ItemType Directory -Path $dir -Force|Out-Null}
function Log([string]$Text){Add-Content -LiteralPath (Join-Path $LogDir 'supervisor.log') -Value ((Get-Date -Format o)+' '+$Text) -Encoding UTF8}
function Read-State {
    if(Test-Path -LiteralPath $StateFile){try{return Get-Content -LiteralPath $StateFile -Raw -Encoding UTF8|ConvertFrom-Json}catch{}}
    return $null
}
function Save-State($value){
    $tmp=$StateFile+'.'+$PID+'.tmp'
    [IO.File]::WriteAllText($tmp,($value|ConvertTo-Json -Depth 5),(New-Object Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $tmp -Destination $StateFile -Force
}
function Owned($ProcessId,$Ticks,[string]$Kind){
    if(!$ProcessId -or !$Ticks){return $false}
    try{
        $p=Get-Process -Id ([int]$ProcessId) -ErrorAction Stop
        if($p.StartTime.ToUniversalTime().Ticks -ne [long]$Ticks){return $false}
        $c=Get-CimInstance Win32_Process -Filter ('ProcessId='+[int]$ProcessId)
        if($Kind -eq 'supervisor'){
            return ($p.Path -ieq $Shell -and $c.CommandLine.Contains($Manager) -and $c.CommandLine.Contains('Supervise'))
        }
        return ($p.Path -ieq $Nginx -and $c.CommandLine.Contains($PrefixArg) -and $c.CommandLine.Contains($ConfigArg))
    }catch{return $false}
}
function Get-Listeners {return @(Get-NetTCPConnection -State Listen -LocalPort 8380 -ErrorAction SilentlyContinue)}
function Read-Health {
    try{
        $r=Invoke-RestMethod -Uri 'http://127.0.0.1:8380/health' -TimeoutSec 2 -ErrorAction Stop
        return ($r.instance -eq 'api-8380' -and $r.theme_enabled -eq $false)
    }catch{return $false}
}
function Get-Children($MasterId,$MasterTicks){
    if(!(Owned $MasterId $MasterTicks 'nginx')){return @()}
    $result=@()
    foreach($c in @(Get-CimInstance Win32_Process -Filter ('ParentProcessId='+[int]$MasterId))){
        try{
            $p=Get-Process -Id $c.ProcessId -ErrorAction Stop
            if($p.Path -ieq $Nginx -and $p.StartTime.ToUniversalTime().Ticks -ge [long]$MasterTicks){
                $result+=@{id=$p.Id;ticks=$p.StartTime.ToUniversalTime().Ticks}
            }
        }catch{}
    }
    return $result
}
function Stop-VerifiedChild($item){
    try{
        $p=Get-Process -Id ([int]$item.id) -ErrorAction Stop
        if($p.Path -ieq $Nginx -and $p.StartTime.ToUniversalTime().Ticks -eq [long]$item.ticks){Stop-Process -Id $p.Id -Force -ErrorAction Stop}
    }catch{}
}
function Invoke-Nginx([string]$Operation){
    if(!(Test-Path -LiteralPath $Nginx)){throw 'Missing API8380 runtime; no existing service will be changed.'}
    $args=@('-p',('"'+$PrefixArg+'"'),'-c',$ConfigArg)
    if($Operation -eq 'Test'){$args+='-t'}elseif($Operation -eq 'Quit'){$args+=@('-s','quit')}
    $out=Join-Path $LogDir ('command-'+$PID+'.out.log')
    $err=Join-Path $LogDir ('command-'+$PID+'.err.log')
    $p=Start-Process -FilePath $Nginx -ArgumentList $args -WorkingDirectory $Prefix -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru -Wait
    if($Operation -eq 'Test'){
        foreach($file in @($out,$err)){if(Test-Path -LiteralPath $file){Get-Content -LiteralPath $file|Out-Host}}
    }
    return $p.ExitCode
}
function Test-Config {if((Invoke-Nginx 'Test') -ne 0){throw 'API8380 config test failed. Existing Nginx/PHP services are NOT touched.'}}
function Stop-Master($MasterId,$MasterTicks){
    if(!(Owned $MasterId $MasterTicks 'nginx')){return}
    $children=@(Get-Children $MasterId $MasterTicks)
    # Supervisor and nginx run under the same account, so quit can signal this instance.
    try{$exitCode=Invoke-Nginx 'Quit';Log ('Quit requested for owned master '+$MasterId+'; signal exit='+$exitCode)}catch{Log 'Instance quit signal failed; using verified PID fallback only.'}
    $deadline=(Get-Date).AddSeconds(15)
    while((Get-Date) -lt $deadline -and (Owned $MasterId $MasterTicks 'nginx')){Start-Sleep -Milliseconds 500}
    if(Owned $MasterId $MasterTicks 'nginx'){
        foreach($child in @(Get-Children $MasterId $MasterTicks)){$children+=,$child}
        Stop-Process -Id ([int]$MasterId) -Force -ErrorAction Stop
    }
    foreach($child in $children){Stop-VerifiedChild $child}
}
function Set-Startup([bool]$Enabled){
    if(!$Enabled){Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue;Write-Host 'Only API8380 startup removed. The running instance is unchanged.';return}
    $args='-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "'+$Manager+'" -Action Supervise'
    $a=New-ScheduledTaskAction -Execute $Shell -Argument $args -WorkingDirectory $Root
    $t=New-ScheduledTaskTrigger -AtStartup;$t.Delay='PT35S'
    $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $s=New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $a -Trigger $t -Principal $p -Settings $s -Description 'Independent loopback API8380 nginx supervisor ONLY. Never manages Baota nginx, PHP, DB, queue or Cap. Respects stop.flag.' -Force|Out-Null
    Write-Host 'API8380 startup enabled. Original tasks and settings were NOT changed.'
}
function Start-Api {
    Test-Config
    $s=Read-State
    if($s -and (Owned $s.supervisorPid $s.supervisorTicks 'supervisor') -and (Read-Health)){
        if(Test-Path -LiteralPath $StopFile){throw 'API8380 is stopping; wait before starting again.'}
        Write-Host 'API8380 already running; nothing restarted.';return
    }
    if(Test-Path -LiteralPath $StopFile){Remove-Item -LiteralPath $StopFile -Force}
    if(!$s -or !(Owned $s.supervisorPid $s.supervisorTicks 'supervisor')){
        if((Get-Listeners).Count -gt 0){throw '8380 occupied. Refusing to stop any process or existing service.'}
        $task=Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        if($task){Start-ScheduledTask -TaskName $TaskName}else{
            Start-Process -FilePath $Shell -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',('"'+$Manager+'"'),'-Action','Supervise') -WorkingDirectory $Root -WindowStyle Hidden|Out-Null
        }
    }
    for($i=0;$i -lt 25;$i++){
        Start-Sleep -Seconds 1
        $s=Read-State
        if($s -and (Owned $s.supervisorPid $s.supervisorTicks 'supervisor') -and (Owned $s.masterPid $s.masterTicks 'nginx') -and (Read-Health)){
            Write-Host 'API8380 ready at http://127.0.0.1:8380. No existing service restarted.';return
        }
    }
    throw 'API8380 start timeout. Check windows\api-8380\logs and nginx\logs; no original project service was restarted.'
}
function Stop-Api {
    [IO.File]::WriteAllText($StopFile,(Get-Date -Format o))
    $s=Read-State
    $deadline=(Get-Date).AddSeconds(25)
    while($s -and (Owned $s.supervisorPid $s.supervisorTicks 'supervisor') -and (Get-Date) -lt $deadline){Start-Sleep -Milliseconds 500;$s=Read-State}
    if($s){
        if(Owned $s.masterPid $s.masterTicks 'nginx'){Stop-Master $s.masterPid $s.masterTicks}
        if(Owned $s.supervisorPid $s.supervisorTicks 'supervisor'){Stop-Process -Id ([int]$s.supervisorPid) -Force -ErrorAction Stop}
    }
    if((Get-Listeners).Count -gt 0){throw '8380 is still occupied. No unrelated process was stopped; inspect ownership manually.'}
    Write-Host 'Only API8380 stopped; maintenance flag preserved. All original services were left alone.'
}
function Show-Status {
    $s=Read-State
    [pscustomobject]@{
        Service='Xboard independent API8380';Listen='127.0.0.1:8380';EntryHealthy=(Read-Health)
        SupervisorRunning=([bool]$s -and (Owned $s.supervisorPid $s.supervisorTicks 'supervisor'))
        MasterRunning=([bool]$s -and (Owned $s.masterPid $s.masterTicks 'nginx'))
        MaintenanceStop=(Test-Path -LiteralPath $StopFile)
        StartupTask=[bool](Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)
    }|Format-List
    Write-Host 'EntryHealthy checks the new listener, not DB health. CAPTCHA/global settings are never changed here.'
}
function Invoke-Supervise {
    $mutex=New-Object Threading.Mutex($false,'Global\XboardDorocloudApi8380Supervisor')
    $held=$false;$proc=$null;$ticks=0;$crashes=0;$children=@()
    try{
        try{$held=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$held=$true}
        if(!$held){return}
        if(Test-Path -LiteralPath $StopFile){return}
        Test-Config
        $self=Get-Process -Id $PID
        # A crashed supervisor can leave its master alive. Recover only the exact
        # instance recorded with PID/start ticks/path/prefix; never kill by port/name.
        $old=Read-State
        if($old -and (Owned $old.masterPid $old.masterTicks 'nginx')){
            Log 'Recovering the verified master left by a previous API8380 supervisor.'
            Stop-Master $old.masterPid $old.masterTicks
        }
        if((Get-Listeners).Count -gt 0){throw '8380 occupied. Supervisor refuses to terminate anything.'}
        Log 'Independent supervisor started.'
        while(!(Test-Path -LiteralPath $StopFile)){
            if($proc){$proc.Refresh()}
            if(!$proc -or $proc.HasExited){
                if($proc){$crashes++;Log ('Owned master exited; restart count='+$crashes);foreach($child in $children){Stop-VerifiedChild $child};$children=@();Start-Sleep -Seconds ([Math]::Min(30,2+$crashes))}
                if(Test-Path -LiteralPath $StopFile){break}
                if((Get-Listeners).Count -gt 0){throw '8380 occupied during recovery. No unrelated process will be stopped.'}
                $proc=Start-Process -FilePath $Nginx -ArgumentList @('-p',('"'+$PrefixArg+'"'),'-c',$ConfigArg) -WorkingDirectory $Prefix -WindowStyle Hidden -RedirectStandardOutput (Join-Path $LogDir 'launch.stdout.log') -RedirectStandardError (Join-Path $LogDir 'launch.stderr.log') -PassThru
                $ticks=$proc.StartTime.ToUniversalTime().Ticks
                Log ('Owned master started: '+$proc.Id)
            }
            if(Owned $proc.Id $ticks 'nginx'){$children=@(Get-Children $proc.Id $ticks)}
            Save-State @{supervisorPid=$PID;supervisorTicks=$self.StartTime.ToUniversalTime().Ticks;masterPid=$proc.Id;masterTicks=$ticks;healthy=(Read-Health);restarts=$crashes;time=(Get-Date -Format o)}
            Start-Sleep -Seconds 3
        }
    }catch{Log ('Supervisor failed: '+$_.Exception.Message);throw}
    finally{
        if($proc -and $ticks){Stop-Master $proc.Id $ticks};foreach($child in $children){Stop-VerifiedChild $child}
        if($held){$mutex.ReleaseMutex()}
        $mutex.Dispose()
    }
}
function Run-Action([string]$Name){
    switch($Name){
        'Start'{Start-Api}
        'Stop'{Stop-Api}
        'Restart'{Test-Config;Stop-Api;Start-Api}
        'Status'{Show-Status}
        'Test'{Test-Config}
        'EnableStartup'{Set-Startup $true}
        'DisableStartup'{Set-Startup $false}
        'Supervise'{Invoke-Supervise}
    }
}
try{
    if($Action -ne 'Menu'){Run-Action $Action;exit 0}
    while($true){
        Write-Host "`nAPI8380 ONLY (does not control original Xboard/Nginx/PHP/Cap)`n1 Start`n2 Stop`n3 Restart API8380 only`n4 Status`n5 Test config`n6 Enable startup (admin)`n7 Disable startup (admin)`n0 Exit"
        $choice=Read-Host 'Select'
        if($choice -eq '0'){break}
        $map=@{'1'='Start';'2'='Stop';'3'='Restart';'4'='Status';'5'='Test';'6'='EnableStartup';'7'='DisableStartup'}
        if($map.ContainsKey($choice)){try{Run-Action $map[$choice]}catch{Write-Host $_.Exception.Message -ForegroundColor Red}}
    }
}catch{Write-Error $_.Exception.Message;exit 1}
