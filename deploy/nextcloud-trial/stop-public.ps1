param(
    [string]$DockerHost = 'npipe:////./pipe/dockerDesktopLinuxEngine'
)

$ErrorActionPreference = 'SilentlyContinue'
$trialDir = $PSScriptRoot
$projectRoot = (Resolve-Path (Join-Path $trialDir '..\..')).Path
$pythonPath = [System.IO.Path]::GetFullPath((Join-Path $projectRoot 'phase0\.venv\Scripts\python.exe'))
$stateFile = Join-Path $trialDir '.public-access-state.json'
$dockerArgs = @('--config',(Join-Path $trialDir '.docker-client'))
if ($DockerHost) { $dockerArgs += @('--host',$DockerHost) }
$compose = $dockerArgs + @('compose','--project-directory',$trialDir,'-f',(Join-Path $trialDir 'compose.yaml'))

$state = $null
if (Test-Path -LiteralPath $stateFile) {
    try { $state = Get-Content -Raw -LiteralPath $stateFile | ConvertFrom-Json } catch { $state = $null }
}

$safeToRemoveState = $true
$stateBelongsToTrial = $state -and
    $state.instance_name -eq 'trial-nextcloud' -and
    $state.source_root -and
    ([System.IO.Path]::GetFullPath($state.source_root.ToString()) -eq $projectRoot)
if ($state -and -not $stateBelongsToTrial) {
    Write-Warning 'State file does not belong to this trial; leaving processes and state untouched.'
    $safeToRemoveState = $false
}
if ($stateBelongsToTrial -and $state.tunnel_names) {
    & docker @dockerArgs rm -f @($state.tunnel_names) 2>$null | Out-Null
}
function Stop-RecordedProcess([string]$Property, [string]$ScriptPattern) {
    if (-not $stateBelongsToTrial -or -not $state.$Property) { return }
    $processId = [int]$state.$Property
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$processId" -ErrorAction SilentlyContinue
    if (-not $process) { return }
    $command = [string]$process.CommandLine
    $executable = [string]$process.ExecutablePath
    if ($executable -ne $pythonPath -or $command -notmatch $ScriptPattern) {
        Write-Warning "Refusing to stop unexpected process $processId recorded as $Property."
        $script:safeToRemoveState = $false
        return
    }
    Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
}
Stop-RecordedProcess 'cognos_pid' 'web[/\\\\]run\.py'
Stop-RecordedProcess 'proxy_pid' 'web[/\\\\]public_webhook_proxy\.py'

if ($stateBelongsToTrial -and $state.config_captured -eq $true -and $state.temporary_trusted_domain_added -and $state.public_host) {
    $trusted = @(& docker @compose exec -T --user www-data app php occ config:system:get trusted_domains | Where-Object { $_ -and $_.Trim() })
    for ($index = $trusted.Count - 1; $index -ge 0; $index--) {
        if ($trusted[$index].Trim() -eq $state.public_host) {
            & docker @compose exec -T --user www-data app php occ config:system:delete trusted_domains $index 2>$null | Out-Null
        }
    }
}
if ($stateBelongsToTrial -and $state.config_captured -eq $true) {
    if ($state.old_overwrite_host -and $state.old_overwrite_host.ToString().Trim()) {
        & docker @compose exec -T --user www-data app php occ config:system:set overwritehost --value="$( $state.old_overwrite_host.ToString().Trim() )" 2>$null | Out-Null
    } else {
        & docker @compose exec -T --user www-data app php occ config:system:delete overwritehost 2>$null | Out-Null
    }
    if ($state.old_overwrite_protocol -and $state.old_overwrite_protocol.ToString().Trim()) {
        & docker @compose exec -T --user www-data app php occ config:system:set overwriteprotocol --value="$( $state.old_overwrite_protocol.ToString().Trim() )" 2>$null | Out-Null
    } else {
        & docker @compose exec -T --user www-data app php occ config:system:delete overwriteprotocol 2>$null | Out-Null
    }
    if ($state.old_trusted_proxies -and $state.old_trusted_proxies.ToString().Trim()) {
        & docker @compose exec -T --user www-data app php occ config:system:set trusted_proxies 0 --value="$( $state.old_trusted_proxies.ToString().Trim() )" 2>$null | Out-Null
    } else {
        & docker @compose exec -T --user www-data app php occ config:system:delete trusted_proxies 2>$null | Out-Null
    }
    if ($state.old_forwarded_headers -and $state.old_forwarded_headers.ToString().Trim()) {
        & docker @compose exec -T --user www-data app php occ config:system:set forwarded_for_headers 0 --value="$( $state.old_forwarded_headers.ToString().Trim() )" 2>$null | Out-Null
    } else {
        & docker @compose exec -T --user www-data app php occ config:system:delete forwarded_for_headers 2>$null | Out-Null
    }
}
if ($stateBelongsToTrial -and $safeToRemoveState) {
    Remove-Item -LiteralPath $stateFile -Force -ErrorAction SilentlyContinue
}
Write-Host 'Temporary Cognos/Talk processes, tunnels, and the recorded Cloudflare Nextcloud URL override were stopped/removed.'
