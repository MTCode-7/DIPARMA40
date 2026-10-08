$ErrorActionPreference = 'Stop'

$ssh = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
$key = Join-Path $env:USERPROFILE '.ssh\diparma_lightsail.pem'
$logDir = Join-Path $env:LOCALAPPDATA 'DI PARMA'
$log = Join-Path $logDir 'peer-tunnel.log'

if (-not (Test-Path $ssh) -or -not (Test-Path $key)) {
    exit 1
}
New-Item -ItemType Directory -Path $logDir -Force | Out-Null

$sshArgs = @(
    '-N',
    '-i', $key,
    '-o', 'BatchMode=yes',
    '-o', 'ExitOnForwardFailure=yes',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-o', 'StrictHostKeyChecking=yes',
    '-R', '127.0.0.1:18080:127.0.0.1:8080',
    'ubuntu@65.2.184.57'
)

while ($true) {
    Add-Content -Path $log -Value "[$(Get-Date -Format o)] Starting peer tunnel"
    & $ssh @sshArgs *>> $log
    Add-Content -Path $log -Value "[$(Get-Date -Format o)] Tunnel exited with code $LASTEXITCODE; retrying"
    Start-Sleep -Seconds 5
}
