param(
    [string]$TaskName = "DRN Excel Sync Agent",
    [string]$Python = "python",
    [string]$AgentDir = $PSScriptRoot
)

$agentPath = Join-Path $AgentDir "sync_agent.py"
$envPath = Join-Path $AgentDir ".env"

if (-not (Test-Path $agentPath)) {
    throw "sync_agent.py bulunamadi: $agentPath"
}

if (-not (Test-Path $envPath)) {
    throw ".env bulunamadi. Once .env.example dosyasini .env olarak kopyalayip doldurun."
}

$action = New-ScheduledTaskAction -Execute $Python -Argument "`"$agentPath`" --env `"$envPath`"" -WorkingDirectory $AgentDir
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description "DRN servis Excel dosyasini panele senkronlar." -Force
Write-Host "Windows gorevi kuruldu: $TaskName"
