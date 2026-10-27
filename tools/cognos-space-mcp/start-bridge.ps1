# 起 Claude Code 桥（claude_bridge.py）。已经在跑就什么都不做，所以可以放进开机/登录自动运行。
#
# 登录 Windows 时自动起（只需注册一次）：
#   powershell -ExecutionPolicy Bypass -File tools\cognos-space-mcp\start-bridge.ps1 -Register
# 不想要了：Unregister-ScheduledTask -TaskName CognosClaudeBridge -Confirm:$false
param(
    [switch]$Register,
    [switch]$NoCommands,
    [switch]$NoSelfMerge,
    [int]$Port = 8643
)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo = Resolve-Path (Join-Path $here '..\..')
$logDir = Join-Path $repo 'deploy\nextcloud-trial\evidence\claude-bridge'

if ($Register) {
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' `
        -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$($MyInvocation.MyCommand.Path)`""
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
    Register-ScheduledTask -TaskName 'CognosClaudeBridge' -Action $action -Trigger $trigger -Force | Out-Null
    Write-Host '已注册：每次登录 Windows 都会起 Claude Code 桥（任务名 CognosClaudeBridge）。'
}

try {
    $health = Invoke-RestMethod -Uri "http://127.0.0.1:$Port/health" -TimeoutSec 3
    if ($health.ok) {
        Write-Host "桥已经在跑（127.0.0.1:$Port），命令：$($health.commands)"
        exit 0
    }
} catch {
    # 没在跑，往下起
}

New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$env:PYTHONIOENCODING = 'utf-8'
$env:COGNOS_BRIDGE_PORT = "$Port"
# 小林 10/27 选了让它在自己的工作目录里跑命令；公网开着时桥自己会关掉
$env:COGNOS_BRIDGE_COMMANDS = $(if ($NoCommands) { '0' } else { '1' })
# 小林 10/27 选了让 Cognos 把检查过了的改动自己合进本机跑着的分支并重启（留标签可退，不推）；-NoSelfMerge 关掉
$env:COGNOS_SELF_MERGE = $(if ($NoSelfMerge) { '0' } else { '1' })
$env:COGNOS_SELF_BASE = 'HEAD'

$python = (Get-Command python -ErrorAction Stop).Source
$process = Start-Process -FilePath $python `
    -ArgumentList @('-u', (Join-Path $here 'claude_bridge.py')) `
    -WorkingDirectory $repo -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $logDir "bridge-$stamp.out.log") `
    -RedirectStandardError (Join-Path $logDir "bridge-$stamp.err.log")
Write-Host "桥起来了：pid $($process.Id)，日志在 $logDir"
