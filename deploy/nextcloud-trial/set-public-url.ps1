param(
    [Parameter(Mandatory=$true)][string]$PublicUrl,
    [string]$DockerHost = 'npipe:////./pipe/dockerDesktopLinuxEngine'
)

$ErrorActionPreference = 'Stop'
if (-not ($PublicUrl -match '^https://([a-z0-9-]+)\.trycloudflare\.com/?$')) { throw 'PublicUrl must be a Cloudflare HTTPS Quick Tunnel URL.' }
$trialDir = $PSScriptRoot
$dockerArgs = @('--config',(Join-Path $trialDir '.docker-client'))
if ($DockerHost) { $dockerArgs += @('--host',$DockerHost) }
$composeArgs = $dockerArgs + @('compose','--project-directory',$trialDir,'-f',(Join-Path $trialDir 'compose.yaml'))
$publicHost = ([uri]$PublicUrl).Host

$trusted = @(& docker @composeArgs exec -T --user www-data app php occ config:system:get trusted_domains | Where-Object { $_ -and $_.Trim() })
if ($trusted -notcontains $publicHost) {
    & docker @composeArgs exec -T --user www-data app php occ config:system:set trusted_domains ($trusted.Count) --value=$publicHost
    if ($LASTEXITCODE -ne 0) { throw 'Could not add trusted domain.' }
}
& docker @composeArgs exec -T --user www-data app php occ config:system:set overwriteprotocol --value=https
& docker @composeArgs exec -T --user www-data app php occ config:system:set overwritehost --value=$publicHost
# Quick Tunnel terminates at a container on the compose network. Trust only that
# private Docker subnet, then use Cloudflare's original client IP for throttling.
& docker @composeArgs exec -T --user www-data app php occ config:system:set trusted_proxies 0 --value='172.18.0.0/16'
& docker @composeArgs exec -T --user www-data app php occ config:system:set forwarded_for_headers 0 --value='HTTP_CF_CONNECTING_IP'
if ($LASTEXITCODE -ne 0) { throw 'Could not set HTTPS/proxy configuration.' }
Write-Host "Nextcloud public URL configured: https://$publicHost" -ForegroundColor Green
Write-Host 'This setting remains until the temporary URL is removed or replaced.' -ForegroundColor Yellow
