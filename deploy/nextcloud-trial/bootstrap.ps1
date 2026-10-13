param([string]$DockerHost = '')
$ErrorActionPreference = 'Stop'
$trialDir = $PSScriptRoot
$dockerArgs = @('--config', (Join-Path $trialDir '.docker-client'))
if ($DockerHost) { $dockerArgs += @('--host', $DockerHost) }
$composeArgs = $dockerArgs + @('compose','--project-directory',$trialDir,'-f',(Join-Path $trialDir 'compose.yaml'))
function Invoke-Occ {
    param([string[]]$OccArgs)
    $previousAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & docker @composeArgs exec -T --user www-data app php occ @OccArgs }
    finally { $ErrorActionPreference = $previousAction }
    if ($LASTEXITCODE -ne 0) { throw "Nextcloud 命令失败：$($OccArgs[0])。未把安装失败计为功能已完成。" }
}
$ready = $false
$deadline = (Get-Date).AddMinutes(5)
do {
    $previousAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $statusText = & docker @composeArgs exec -T --user www-data app php occ status --output=json 2>$null }
    finally { $ErrorActionPreference = $previousAction }
    if ($LASTEXITCODE -eq 0) {
        try {
            $status = ($statusText -join "`n") | ConvertFrom-Json
            $ready = $status.installed -and -not $status.maintenance
        } catch { $ready = $false }
    }
    if ($ready) { break }
    Write-Output '等待 Nextcloud 初始化就绪…'
    Start-Sleep -Seconds 3
} while ((Get-Date) -lt $deadline)
if (-not $ready) { throw '初始化在 5 分钟内未就绪；保留状态，查看 app 日志后原地重试。' }
# Validate the local extension before enabling it; no App Store download.
foreach ($relativePath in @('lib/AppInfo/Application.php','lib/Listener/TaskFeedbackListener.php','lib/Controller/TaskController.php','appinfo/routes.php','lib/Migration/Version000200Date20261007000000.php','lib/Migration/Version000300Date20261007010000.php','lib/Service/TaskAuditService.php','lib/Service/TaskProgressService.php','lib/Migration/Version000600Date20261007030000.php','lib/Controller/LifecycleController.php','lib/Controller/AssignmentController.php','lib/Controller/EditController.php','lib/Controller/RenameController.php', 'lib/Controller/PersonalTasksController.php', 'lib/Controller/BoardActivityController.php', 'lib/Service/TaskEventsService.php','lib/Migration/Version000400Date20261007020000.php')) {
    & docker @composeArgs exec -T --user www-data app php -l ('/var/www/html/custom_apps/cognos/' + $relativePath)
    if ($LASTEXITCODE -ne 0) { throw 'Cognos 扩展 PHP 检查失败；未启用。' }
}
Invoke-Occ @('upgrade')
$upgradedText = & docker @composeArgs exec -T --user www-data app php occ status --output=json
if ($LASTEXITCODE -ne 0) { throw '升级后无法读取状态；未继续安装应用。' }
$upgraded = ($upgradedText -join "`n") | ConvertFrom-Json
if (-not $upgraded.installed -or $upgraded.maintenance -or $upgraded.needsDbUpgrade) {
    throw '升级后仍未就绪；保留数据，未继续安装应用。'
}
$installedText = & docker @composeArgs exec -T --user www-data app php occ app:list --output=json
if ($LASTEXITCODE -ne 0) { throw '无法读取已安装应用；未继续安装。' }
$installed = ($installedText -join "`n") | ConvertFrom-Json
foreach ($appId in @('deck','spreed','collectives','groupfolders')) {
    if ($installed.enabled.PSObject.Properties.Name -contains $appId) {
        Write-Output "$appId 已启用。"
    } elseif ($installed.disabled.PSObject.Properties.Name -contains $appId) {
        Invoke-Occ @('app:enable',$appId)
    } else {
        Invoke-Occ @('app:install',$appId)
    }
}
Invoke-Occ @('config:system:set','default_language','--value=zh_CN')
Invoke-Occ @('config:system:set','default_locale','--value=zh_CN')
Invoke-Occ @('config:system:set','default_timezone','--value=Asia/Shanghai')
Invoke-Occ @('background:cron')
Invoke-Occ @('app:enable','cognos')
Invoke-Occ @('app:list')
Write-Output '协作应用安装命令完成；仍须双成员与浏览器旅程验收。'
