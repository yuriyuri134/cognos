# 让 ChatGPT（网页版开发者模式的连接器）走进 Cognos：起 http_server.py，再开一条 Cloudflare 临时隧道。
# 打印出来的整条地址填进 ChatGPT：设置 → 应用与连接器 → 高级 → 打开开发者模式 → 创建连接器，认证选「不需要」。
# 这个窗口关掉（或 Ctrl+C），隧道和入口就都停了；临时隧道每次的地址都不一样，重开以后要在 ChatGPT 里改地址。
# 只开 MCP 入口这一个口子，8780 本身不开到公网。
param(
    [int]$Port = 8645
)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo = Resolve-Path (Join-Path $here '..\..')
$logDir = Join-Path $repo 'deploy\nextcloud-trial\evidence\chatgpt-mcp'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$python = (Get-Command python -ErrorAction Stop).Source
$cloudflared = Get-Command cloudflared -ErrorAction SilentlyContinue

$env:PYTHONIOENCODING = 'utf-8'
$env:COGNOS_CHATGPT_MCP_PORT = "$Port"
# 本机有 cloudflared 就只听 127.0.0.1；用 docker 跑隧道时容器要从 host.docker.internal 进来，只能听 0.0.0.0（地址里有口令）
$env:COGNOS_CHATGPT_MCP_HOST = $(if ($cloudflared) { '127.0.0.1' } else { '0.0.0.0' })

$server = Start-Process -FilePath $python -ArgumentList @('-u', (Join-Path $here 'http_server.py')) `
    -WorkingDirectory $repo -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $logDir "server-$stamp.out.log") `
    -RedirectStandardError (Join-Path $logDir "server-$stamp.err.log")
$tunnelOut = Join-Path $logDir "tunnel-$stamp.out.log"
$tunnelErr = Join-Path $logDir "tunnel-$stamp.err.log"
$tunnel = $null
try {
    Start-Sleep -Seconds 2
    if ($server.HasExited) { throw "入口没起来，看 $logDir 里的 server-$stamp.err.log" }
    $health = Invoke-RestMethod -Uri "http://127.0.0.1:$Port/health" -TimeoutSec 5
    if (-not $health.ok) { throw '入口起来了但不回 /health' }

    if ($cloudflared) {
        $tunnel = Start-Process -FilePath $cloudflared.Source -ArgumentList @('tunnel','--no-autoupdate','--url',"http://127.0.0.1:$Port") `
            -WindowStyle Hidden -PassThru -RedirectStandardOutput $tunnelOut -RedirectStandardError $tunnelErr
    } else {
        $image = 'cloudflare/cloudflared@sha256:9b49eed8f62806d5d45ddf59ecefb5710429598ea6d3fcccd2af938f621b2b07'
        $tunnel = Start-Process -FilePath 'docker' -ArgumentList @('run','--rm','--name','cognos-chatgpt-tunnel','--add-host','host.docker.internal:host-gateway',
            $image,'tunnel','--no-autoupdate','--protocol','http2','--url',"http://host.docker.internal:$Port") `
            -NoNewWindow -PassThru -RedirectStandardOutput $tunnelOut -RedirectStandardError $tunnelErr
    }
    $deadline = (Get-Date).AddSeconds(60)
    $url = ''
    do {
        Start-Sleep -Seconds 2
        $text = (@($tunnelOut, $tunnelErr) | Where-Object { Test-Path $_ } | ForEach-Object { Get-Content -Raw $_ }) -join "`n"
        $url = [regex]::Match($text, 'https://[a-z0-9-]+\.trycloudflare\.com').Value
    } while (-not $url -and -not $tunnel.HasExited -and (Get-Date) -lt $deadline)
    if (-not $url) { throw "隧道没开起来，看 $tunnelErr" }

    $tokenFile = Join-Path $repo 'deploy\nextcloud-trial\.secrets\chatgpt-mcp-token'
    $token = if ($env:COGNOS_CHATGPT_TOKEN) { $env:COGNOS_CHATGPT_TOKEN } else { (Get-Content -Raw $tokenFile).Trim() }
    Write-Host ''
    Write-Host '填进 ChatGPT 连接器的地址（带口令，别贴到别处）：' -ForegroundColor Green
    Write-Host "  $url/mcp/$token"
    Write-Host ''
    Write-Host '关掉这个窗口或按 Ctrl+C 就停。'
    while (-not $tunnel.HasExited -and -not $server.HasExited) { Start-Sleep -Seconds 2 }
} finally {
    foreach ($p in @($tunnel, $server)) { if ($p -and -not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue } }
    if (-not $cloudflared) { & docker rm -f cognos-chatgpt-tunnel 2>$null | Out-Null }
}
