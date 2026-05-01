# Police bitis hatirlatici Windows Task Scheduler kurulumu
# Kullanim:
#   powershell -ExecutionPolicy Bypass -File install_policy_reminder_task.ps1 `
#     -PhpExe "C:\xampp\php\php.exe" `
#     -ScriptPath "C:\inetpub\wwwroot\drnekin\panel\cron\policy_reminder.php" `
#     -Time "08:00"

param(
    [string]$TaskName   = "DRN Police Reminder",
    [string]$PhpExe     = "C:\php\php.exe",
    [string]$ScriptPath = "$PSScriptRoot\policy_reminder.php",
    [string]$Time       = "08:00",
    [string]$User       = $env:USERNAME
)

if (-not (Test-Path $PhpExe))     { Write-Error "PHP bulunamadi: $PhpExe"; exit 1 }
if (-not (Test-Path $ScriptPath)) { Write-Error "Script bulunamadi: $ScriptPath"; exit 1 }

$action    = New-ScheduledTaskAction    -Execute $PhpExe -Argument "`"$ScriptPath`""
$trigger   = New-ScheduledTaskTrigger   -Daily -At $Time
$principal = New-ScheduledTaskPrincipal -UserId $User -LogonType S4U -RunLevel Highest
$settings  = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "DRN paneli police bitis hatirlatici - gunde 1 kez calisir."

Write-Host "Task kuruldu: $TaskName ($Time)"
