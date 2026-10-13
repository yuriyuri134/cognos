param([string]$DockerHost = 'npipe:////./pipe/dockerDesktopLinuxEngine')
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'start.ps1') -DockerHost $DockerHost
if ($LASTEXITCODE -ne 0) { throw '启动失败；停止应用安装。' }
& (Join-Path $PSScriptRoot 'bootstrap.ps1') -DockerHost $DockerHost
