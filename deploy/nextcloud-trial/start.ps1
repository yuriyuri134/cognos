param(
    [string]$DockerHost = '',
    [switch]$PrepareOnly
)
$ErrorActionPreference = 'Stop'
$trialDir = $PSScriptRoot
$secretDir = Join-Path $trialDir '.secrets'
$configDir = Join-Path $trialDir '.docker-client'
New-Item -ItemType Directory -Force -Path $secretDir,$configDir | Out-Null
foreach ($name in @('db-password','admin-password')) {
    $file = Join-Path $secretDir $name
    if (-not (Test-Path -LiteralPath $file)) {
        $bytes = New-Object byte[] 32
        $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
        try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
        $value = ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
        [System.IO.File]::WriteAllText($file, $value, (New-Object System.Text.UTF8Encoding($false)))
    }
}
$dockerArgs = @('--config', $configDir)
if ($DockerHost) { $dockerArgs += @('--host', $DockerHost) }
$composeArgs = $dockerArgs + @('compose','--project-directory',$trialDir,'-f',(Join-Path $trialDir 'compose.yaml'))
& docker @composeArgs config --quiet
if ($LASTEXITCODE -ne 0) { throw 'Compose 配置检查失败。' }
if ($PrepareOnly) { Write-Output '配置已准备并解析；未拉取镜像或创建服务。'; exit 0 }
& docker @dockerArgs info --format '{{.ServerVersion}}'
if ($LASTEXITCODE -ne 0) { throw 'Docker 引擎未就绪。启动 Docker Desktop 的 Linux 容器引擎后重试，或指定 -DockerHost。' }
& docker @composeArgs up -d
if ($LASTEXITCODE -ne 0) { throw '启动失败。保留已有卷与密钥，解决错误后原地重试。' }
Write-Output '正在初始化 http://127.0.0.1:8780；管理员 cognos-admin，密码仅保存在 .secrets/admin-password。'
Write-Output '准备就绪后执行 bootstrap.ps1 安装协作应用；首次安装与网络下载需时间。'
