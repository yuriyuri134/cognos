param(
    [Parameter(Mandatory=$true)][string]$WebhookUrl,
    [string]$BotName = 'Cognos',
    [string]$Description = 'Cognos AI bridge for the current Talk project',
    [string]$DockerHost = 'npipe:////./pipe/dockerDesktopLinuxEngine',
    [string[]]$ConversationToken = @(),
    [switch]$ReplaceExisting
)

$ErrorActionPreference = 'Stop'
$trialDir = $PSScriptRoot
$secretDir = Join-Path $trialDir '.secrets'
New-Item -ItemType Directory -Force -Path $secretDir | Out-Null
$secretFile = Join-Path $secretDir 'talk-bot-secret'

if (-not ($WebhookUrl -match '^https://')) { throw 'WebhookUrl must use HTTPS.' }
if (-not (Test-Path -LiteralPath $secretFile)) {
    $bytes = New-Object byte[] 48
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $secret = ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
    [System.IO.File]::WriteAllText($secretFile, $secret, (New-Object System.Text.UTF8Encoding($false)))
} else {
    $secret = (Get-Content -Raw -LiteralPath $secretFile).Trim()
}
if ($secret.Length -lt 40) { throw 'Stored Talk bot secret is too short.' }

$dockerArgs = @('--config',(Join-Path $trialDir '.docker-client'))
if ($DockerHost) { $dockerArgs += @('--host',$DockerHost) }
$composeArgs = $dockerArgs + @('compose','--project-directory',$trialDir,'-f',(Join-Path $trialDir 'compose.yaml'))
$existing = (& docker @composeArgs exec -T --user www-data app php occ talk:bot:list --output=json | ConvertFrom-Json)
$bot = @($existing | Where-Object { $_.name -eq $BotName }) | Select-Object -First 1
if ($bot -and -not $ReplaceExisting) {
    Write-Host "Bot '$BotName' already exists; secret remains in $secretFile."
    $botId = $bot.id
} else {
    if ($bot) {
        & docker @composeArgs exec -T --user www-data app php occ talk:bot:uninstall --no-interaction $bot.id
        if ($LASTEXITCODE -ne 0) { throw "Could not replace existing Talk bot '$BotName'." }
    }
    & docker @composeArgs exec -T --user www-data app php occ talk:bot:install --output=json --no-interaction --feature=webhook --feature=response $BotName $secret $WebhookUrl $Description | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Talk bot installation failed.' }
    $installed = & docker @composeArgs exec -T --user www-data app php occ talk:bot:list --output=json | ConvertFrom-Json
    $bot = @($installed | Where-Object { $_.name -eq $BotName }) | Select-Object -First 1
    if (-not $bot) { throw "Talk bot '$BotName' was installed but could not be read back." }
    $botId = $bot.id
}

if ($ConversationToken.Count -gt 0) {
    & docker @composeArgs exec -T --user www-data app php occ talk:bot:setup --no-interaction $botId @ConversationToken
    if ($LASTEXITCODE -ne 0) { throw 'Talk bot setup for conversation failed.' }
}

Write-Host "Bot installed: $BotName (id $botId)" -ForegroundColor Green
Write-Host "Webhook: $WebhookUrl" -ForegroundColor Green
if ($ReplaceExisting) { Write-Host 'Existing bot was replaced so its webhook URL matches this trial.' -ForegroundColor Yellow }
Write-Host "Secret file: $secretFile" -ForegroundColor Yellow
Write-Host 'Set COGNOS_TALK_BOT_SECRET to the contents of that file in the Cognos process environment.'
if ($ConversationToken.Count -eq 0) { Write-Host 'No conversation was configured. Add it later with talk:bot:setup and the bot id above.' }
