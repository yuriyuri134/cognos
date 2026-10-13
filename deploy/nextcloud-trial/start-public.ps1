param(
    [string]$DockerHost = 'npipe:////./pipe/dockerDesktopLinuxEngine',
    # Keep the isolated trial away from the canonical local instance on 8765.
    [int]$CognosPort = 8775,
    [int]$ProxyPort = 8776,
    [string]$DefaultProjectId = '',
    [string]$DefaultTarget = '',
    [string]$CognosStateDir = '',
    [string]$CognosVaultDir = '',
    [string]$LogDir = ''
)

$ErrorActionPreference = 'Stop'
$trialDir = $PSScriptRoot
$stateFile = Join-Path $trialDir '.public-access-state.json'
$projectRoot = (Resolve-Path (Join-Path $trialDir '..\..')).Path
$webDir = Join-Path $projectRoot 'web'
if (-not $CognosStateDir) { $CognosStateDir = Join-Path $trialDir '.cognos-state' }
if (-not $CognosVaultDir) { $CognosVaultDir = Join-Path $trialDir '.cognos-vault' }
New-Item -ItemType Directory -Force -Path $CognosStateDir,$CognosVaultDir | Out-Null
$python = Join-Path $projectRoot 'phase0\.venv\Scripts\python.exe'
if (-not (Test-Path -LiteralPath $python)) { throw "Python venv not found: $python" }
if (-not $LogDir) { $LogDir = Join-Path $trialDir 'evidence\public-access' }
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

Write-Host 'This starts temporary HTTPS Quick Tunnels. URLs change when stopped.' -ForegroundColor Yellow
Write-Host 'Do not use this for persistent production data, public registration, or long-term storage.' -ForegroundColor Yellow

$nextcloudLog = Join-Path $LogDir 'nextcloud-tunnel.out.log'
$nextcloudErr = Join-Path $LogDir 'nextcloud-tunnel.err.log'
$cognosLog = Join-Path $LogDir 'cognos-tunnel.out.log'
$cognosErr = Join-Path $LogDir 'cognos-tunnel.err.log'
$cognosAppLog = Join-Path $LogDir 'cognos.out.log'
$cognosAppErr = Join-Path $LogDir 'cognos.err.log'
$proxyLog = Join-Path $LogDir 'webhook-proxy.out.log'
$proxyErr = Join-Path $LogDir 'webhook-proxy.err.log'
foreach ($file in @($nextcloudLog, $nextcloudErr, $cognosLog, $cognosErr, $cognosAppLog, $cognosAppErr, $proxyLog, $proxyErr)) { if (Test-Path $file) { Remove-Item -Force $file } }

$secretFile = Join-Path $trialDir '.secrets\talk-bot-secret'
if (-not (Test-Path -LiteralPath $secretFile)) {
    $bytes = New-Object byte[] 48
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $secret = ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
    [System.IO.File]::WriteAllText($secretFile, $secret, (New-Object System.Text.UTF8Encoding($false)))
} else { $secret = (Get-Content -Raw -LiteralPath $secretFile).Trim() }
if ($secret.Length -lt 40) { throw 'Talk bot secret is missing or too short.' }

$cognos = $null
$proxy = $null
$nextcloud = $null
$cloudflaredCognos = $null
$cloudflaredNextcloud = $null
$oldTrusted = $null
$oldOverwriteHost = $null
$oldOverwriteProtocol = $null
$oldTrustedProxies = $null
$oldForwardedHeaders = $null
$publicHost = $null
$previousInstanceName = $null
$previousInstanceRole = $null
$stateOwnedByThisRun = $false
$proxyConfigCaptured = $false
$dockerArgs = @('--config',(Join-Path $trialDir '.docker-client'))
if ($DockerHost) { $dockerArgs += @('--host',$DockerHost) }
$composeArgs = $dockerArgs + @('compose','--project-directory',$trialDir,'-f',(Join-Path $trialDir 'compose.yaml'))

function Assert-PortFree([int]$Port) {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $Port)
    try {
        $listener.Start()
    } catch {
        throw "Port $Port is already in use. Refusing to attach the trial to another Cognos instance."
    } finally {
        if ($listener) { $listener.Stop() }
    }
}
Assert-PortFree $CognosPort
Assert-PortFree $ProxyPort

try {
    # Cognos itself stays on localhost. Only the fixed webhook proxy is tunnelled.
    $previousSecret = $env:COGNOS_TALK_BOT_SECRET
    $previousNextcloud = $env:COGNOS_TALK_NEXTCLOUD_URL
    $previousState = $env:COGNOS_THREAD_STATE_DIR
    $previousVault = $env:COGNOS_VAULT_PATH
    $previousInstanceName = $env:COGNOS_INSTANCE_NAME
    $previousInstanceRole = $env:COGNOS_INSTANCE_ROLE
    $env:COGNOS_INSTANCE_NAME = 'trial-nextcloud'
    $env:COGNOS_INSTANCE_ROLE = 'trial'
    $env:COGNOS_TALK_BOT_SECRET = $secret
    $env:COGNOS_TALK_NEXTCLOUD_URL = 'http://127.0.0.1:8780'
    $env:COGNOS_THREAD_STATE_DIR = (Resolve-Path $CognosStateDir).Path
    $env:COGNOS_VAULT_PATH = (Resolve-Path $CognosVaultDir).Path
    $previousProject = $env:COGNOS_TALK_DEFAULT_PROJECT_ID
    $previousTarget = $env:COGNOS_TALK_DEFAULT_TARGET
    if ($DefaultProjectId) { $env:COGNOS_TALK_DEFAULT_PROJECT_ID = $DefaultProjectId } else { Remove-Item Env:COGNOS_TALK_DEFAULT_PROJECT_ID -ErrorAction SilentlyContinue }
    if ($DefaultTarget) { $env:COGNOS_TALK_DEFAULT_TARGET = $DefaultTarget } else { Remove-Item Env:COGNOS_TALK_DEFAULT_TARGET -ErrorAction SilentlyContinue }
    $cognos = Start-Process -FilePath $python -ArgumentList @((Join-Path $webDir 'run.py'),'--host','127.0.0.1','--port',"$CognosPort") -WorkingDirectory $webDir -RedirectStandardOutput $cognosAppLog -RedirectStandardError $cognosAppErr -PassThru
    $proxy = Start-Process -FilePath $python -ArgumentList @((Join-Path $webDir 'public_webhook_proxy.py'),'--host','0.0.0.0','--port',"$ProxyPort",'--upstream',"http://127.0.0.1:$CognosPort") -WorkingDirectory $webDir -RedirectStandardOutput $proxyLog -RedirectStandardError $proxyErr -PassThru
    @{ instance_name = 'trial-nextcloud'; instance_role = 'trial'; source_root = $projectRoot; cognos_pid = $cognos.Id; proxy_pid = $proxy.Id; cognos_port = $CognosPort; proxy_port = $ProxyPort; tunnel_names = @('cognos-talk-tunnel','cognos-nextcloud-tunnel') } | ConvertTo-Json | Set-Content -LiteralPath $stateFile -Encoding UTF8
    $stateOwnedByThisRun = $true
    Start-Sleep -Seconds 3

    # Quick Tunnel containers reach host services via host.docker.internal.
    $image = 'cloudflare/cloudflared@sha256:9b49eed8f62806d5d45ddf59ecefb5710429598ea6d3fcccd2af938f621b2b07'
    $cognosRun = @('run','--rm','--name','cognos-talk-tunnel','--add-host','host.docker.internal:host-gateway',$image,'tunnel','--no-autoupdate','--protocol','http2','--url')
    $nextcloudRun = @('run','--rm','--name','cognos-nextcloud-tunnel','--network','cognos-nextcloud-trial_default',$image,'tunnel','--no-autoupdate','--protocol','http2','--url')
    $cloudflaredCognos = Start-Process -FilePath 'docker' -ArgumentList ($cognosRun + @("http://host.docker.internal:$ProxyPort")) -NoNewWindow -RedirectStandardOutput $cognosLog -RedirectStandardError $cognosErr -PassThru
    $cloudflaredNextcloud = Start-Process -FilePath 'docker' -ArgumentList ($nextcloudRun + @('http://app:80')) -NoNewWindow -RedirectStandardOutput $nextcloudLog -RedirectStandardError $nextcloudErr -PassThru

    $deadline = (Get-Date).AddSeconds(60)
    do {
        Start-Sleep -Seconds 2
        $nextText = (@($nextcloudLog, $nextcloudErr) | Where-Object { Test-Path $_ } | ForEach-Object { Get-Content -Raw $_ }) -join "`n"
        $cognosText = (@($cognosLog, $cognosErr) | Where-Object { Test-Path $_ } | ForEach-Object { Get-Content -Raw $_ }) -join "`n"
        $nextUrl = [regex]::Match($nextText, 'https://[a-z0-9-]+\.trycloudflare\.com').Value
        $cognosUrl = [regex]::Match($cognosText, 'https://[a-z0-9-]+\.trycloudflare\.com').Value
    } while ((!$nextUrl -or !$cognosUrl) -and (Get-Date) -lt $deadline)

    if (-not $nextUrl -or -not $cognosUrl) { throw 'Cloudflare Quick Tunnel startup failed. Inspect evidence/public-access/*.log.' }

    $oldTrusted = @(& docker @composeArgs exec -T --user www-data app php occ config:system:get trusted_domains | Where-Object { $_ -and $_.Trim() })
    $publicHost = ([uri]$nextUrl).Host
    $oldOverwriteHost = (& docker @composeArgs exec -T --user www-data app php occ config:system:get overwritehost 2>$null | Select-Object -First 1)
    $oldOverwriteProtocol = (& docker @composeArgs exec -T --user www-data app php occ config:system:get overwriteprotocol 2>$null | Select-Object -First 1)
    $oldTrustedProxies = (& docker @composeArgs exec -T --user www-data app php occ config:system:get trusted_proxies 2>$null | Select-Object -First 1)
    $oldForwardedHeaders = (& docker @composeArgs exec -T --user www-data app php occ config:system:get forwarded_for_headers 2>$null | Select-Object -First 1)
    $proxyConfigCaptured = $true
    $state = @{ instance_name = 'trial-nextcloud'; instance_role = 'trial'; source_root = $projectRoot; cognos_pid = $cognos.Id; proxy_pid = $proxy.Id; cognos_port = $CognosPort; proxy_port = $ProxyPort; tunnel_names = @('cognos-talk-tunnel','cognos-nextcloud-tunnel'); public_host = $publicHost; old_overwrite_host = $oldOverwriteHost; old_overwrite_protocol = $oldOverwriteProtocol; old_trusted_proxies = $oldTrustedProxies; old_forwarded_headers = $oldForwardedHeaders; config_captured = $proxyConfigCaptured; temporary_trusted_domain_added = $true } | ConvertTo-Json
    $state | Set-Content -LiteralPath $stateFile -Encoding UTF8
    & (Join-Path $trialDir 'set-public-url.ps1') -PublicUrl $nextUrl -DockerHost $DockerHost
    if ($LASTEXITCODE -ne 0) { throw 'Could not configure Nextcloud for the temporary HTTPS URL.' }
    Write-Host "Nextcloud (members): $nextUrl" -ForegroundColor Green
    Write-Host "Talk webhook: $cognosUrl/api/integrations/nextcloud-talk/webhook" -ForegroundColor Green
    Write-Host ''
    Write-Host 'Apply the temporary Nextcloud HTTPS URL before inviting members:' -ForegroundColor Yellow
    Write-Host "  & (Join-Path `$PSScriptRoot 'set-public-url.ps1') -PublicUrl '$nextUrl' -DockerHost '$DockerHost'"
    Write-Host 'Before inviting members, install the bot:' -ForegroundColor Yellow
    Write-Host "  .\install-talk-bot.ps1 -WebhookUrl '$cognosUrl/api/integrations/nextcloud-talk/webhook'"
    Write-Host "  # then add the printed bot to a Talk room with talk:bot:setup"
    Write-Host ''
    Write-Host 'The temporary URLs stop when this window exits. Press Ctrl+C to stop.'
    while (-not $cloudflaredCognos.HasExited -and -not $cloudflaredNextcloud.HasExited) { Start-Sleep -Seconds 2 }
}
finally {
    foreach ($process in @($cloudflaredCognos, $cloudflaredNextcloud, $proxy, $cognos)) {
        if ($process -and -not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
    }
    & docker rm -f cognos-talk-tunnel cognos-nextcloud-tunnel 2>$null
    if ($proxyConfigCaptured) {
        if ($oldTrusted -and $publicHost -and ($oldTrusted -notcontains $publicHost)) {
            $currentTrusted = @(& docker @composeArgs exec -T --user www-data app php occ config:system:get trusted_domains | Where-Object { $_ -and $_.Trim() })
            for ($index = $currentTrusted.Count - 1; $index -ge 0; $index--) {
                if ($currentTrusted[$index].Trim() -eq $publicHost) {
                    & docker @composeArgs exec -T --user www-data app php occ config:system:delete trusted_domains $index 2>$null
                }
            }
        }
        if ($oldOverwriteHost -and $oldOverwriteHost.Trim()) {
            & docker @composeArgs exec -T --user www-data app php occ config:system:set overwritehost --value="$( $oldOverwriteHost.Trim() )" 2>$null
        } else {
            & docker @composeArgs exec -T --user www-data app php occ config:system:delete overwritehost 2>$null
        }
        if ($oldOverwriteProtocol -and $oldOverwriteProtocol.Trim()) {
            & docker @composeArgs exec -T --user www-data app php occ config:system:set overwriteprotocol --value="$( $oldOverwriteProtocol.Trim() )" 2>$null
        } else {
            & docker @composeArgs exec -T --user www-data app php occ config:system:delete overwriteprotocol 2>$null
        }
        if ($oldTrustedProxies -and $oldTrustedProxies.ToString().Trim()) {
            & docker @composeArgs exec -T --user www-data app php occ config:system:set trusted_proxies 0 --value="$( $oldTrustedProxies.ToString().Trim() )" 2>$null
        } else {
            & docker @composeArgs exec -T --user www-data app php occ config:system:delete trusted_proxies 2>$null
        }
        if ($oldForwardedHeaders -and $oldForwardedHeaders.ToString().Trim()) {
            & docker @composeArgs exec -T --user www-data app php occ config:system:set forwarded_for_headers 0 --value="$( $oldForwardedHeaders.ToString().Trim() )" 2>$null
        } else {
            & docker @composeArgs exec -T --user www-data app php occ config:system:delete forwarded_for_headers 2>$null
        }
    }
    $recordedState = $null
    if (Test-Path -LiteralPath $stateFile) {
        try { $recordedState = Get-Content -Raw -LiteralPath $stateFile | ConvertFrom-Json } catch { $recordedState = $null }
    }
    $ownsState = $recordedState -and $cognos -and $proxy -and
        ([int]$recordedState.cognos_pid -eq [int]$cognos.Id) -and
        ([int]$recordedState.proxy_pid -eq [int]$proxy.Id)
    if ($stateOwnedByThisRun -and $ownsState) {
        Remove-Item -LiteralPath $stateFile -Force -ErrorAction SilentlyContinue
    }
    if ($null -eq $previousSecret) { Remove-Item Env:COGNOS_TALK_BOT_SECRET -ErrorAction SilentlyContinue } else { $env:COGNOS_TALK_BOT_SECRET = $previousSecret }
    if ($null -eq $previousNextcloud) { Remove-Item Env:COGNOS_TALK_NEXTCLOUD_URL -ErrorAction SilentlyContinue } else { $env:COGNOS_TALK_NEXTCLOUD_URL = $previousNextcloud }
    if ($null -eq $previousState) { Remove-Item Env:COGNOS_THREAD_STATE_DIR -ErrorAction SilentlyContinue } else { $env:COGNOS_THREAD_STATE_DIR = $previousState }
    if ($null -eq $previousVault) { Remove-Item Env:COGNOS_VAULT_PATH -ErrorAction SilentlyContinue } else { $env:COGNOS_VAULT_PATH = $previousVault }
    if ($null -eq $previousProject) { Remove-Item Env:COGNOS_TALK_DEFAULT_PROJECT_ID -ErrorAction SilentlyContinue } else { $env:COGNOS_TALK_DEFAULT_PROJECT_ID = $previousProject }
    if ($null -eq $previousTarget) { Remove-Item Env:COGNOS_TALK_DEFAULT_TARGET -ErrorAction SilentlyContinue } else { $env:COGNOS_TALK_DEFAULT_TARGET = $previousTarget }
    if ($null -eq $previousInstanceName) { Remove-Item Env:COGNOS_INSTANCE_NAME -ErrorAction SilentlyContinue } else { $env:COGNOS_INSTANCE_NAME = $previousInstanceName }
    if ($null -eq $previousInstanceRole) { Remove-Item Env:COGNOS_INSTANCE_ROLE -ErrorAction SilentlyContinue } else { $env:COGNOS_INSTANCE_ROLE = $previousInstanceRole }
}
