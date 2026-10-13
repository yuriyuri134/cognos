param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$RunArgs
)

$ErrorActionPreference = "Stop"

$webDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$python = Join-Path $webDir "..\phase0\.venv\Scripts\python.exe"

Write-Host "=== Cognos First Organization Slice ===" -ForegroundColor Cyan

# 1. Check Python virtual environment
if (-not (Test-Path -LiteralPath $python)) {
    Write-Host "[FAIL] Python venv not found: $python" -ForegroundColor Red
    Write-Host "       Please create venv in phase0 and install requirements." -ForegroundColor Yellow
    exit 1
}
Write-Host "[OK] Python venv: Ready" -ForegroundColor Green

# 2. Check Co-existing Participants
$envFile = Join-Path $webDir ".env"
if (Test-Path -LiteralPath $envFile) {
    Write-Host "[OK] Participant: Gemini (gemini-3.7-flash)" -ForegroundColor Green
}
try {
    $loginOutput = & codex login status 2>&1
    if ($LASTEXITCODE -eq 0 -and $loginOutput -match "Logged in") {
        Write-Host "[OK] Participant: Codex (Logged in)" -ForegroundColor Green
    } else {
        Write-Host "[WARN] Participant: Codex (Not logged in or offline)" -ForegroundColor Yellow
    }
} catch {
    Write-Host "[WARN] Participant: Codex (CLI not checked)" -ForegroundColor Yellow
}

Write-Host "----------------------------------------"
Write-Host "Starting Web Server (default http://127.0.0.1:8765; run.py accepts --host / --port) ..." -ForegroundColor Cyan
Set-Location -LiteralPath $webDir
& $python (Join-Path $webDir "run.py") --open @RunArgs

