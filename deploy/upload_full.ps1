# DI PARMA | Full project upload (excludes secrets and runtime junk)
$ErrorActionPreference = 'Stop'
$KEY  = Join-Path $env:USERPROFILE '.ssh\diparma_lightsail.pem'
$REMOTE = 'ubuntu@65.2.184.57'
$ROOT = Split-Path $PSScriptRoot -Parent
Set-Location $ROOT

$stamp  = Get-Date -Format 'yyyyMMdd-HHmmss'
$bundle = Join-Path $env:TEMP "diparma-full-$stamp.tar.gz"

Write-Host "Packing project (no .env / logs / cache)..."
tar.exe -czf $bundle `
  --exclude=.git `
  --exclude=.env `
  --exclude=.env.production `
  --exclude=logs `
  --exclude=cache `
  --exclude=tmp `
  --exclude=backups `
  --exclude=node_modules `
  --exclude=vendor `
  --exclude='*.log' `
  .

Write-Host "Bundle: $bundle  size=$((Get-Item $bundle).Length)"
$sshOpts = @('-i', $KEY, '-o', 'StrictHostKeyChecking=accept-new', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=8', '-o', 'ConnectTimeout=20')
scp.exe @sshOpts $bundle "${REMOTE}:/tmp/diparma-full.tar.gz"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

ssh.exe @sshOpts $REMOTE "bash -s" -- @'
set -e
STAMP=$(date +%F-%H%M%S)
mkdir -p /tmp/diparma-full-extract
tar -xzf /tmp/diparma-full.tar.gz -C /tmp/diparma-full-extract
for APP in /var/www/html/DIPARMA40 /var/www/diparma; do
  if [ -d "$APP" ]; then
    echo "-- Deploy $APP"
    timeout 90 sudo tar czf "/root/pre-full-$STAMP-$(basename "$APP").tar.gz" -C "$(dirname "$APP")" "$(basename "$APP")" 2>/dev/null || echo "   skip_slow_backup"
    sudo rsync -a --delete \
      --exclude ".env" \
      --exclude ".env.production" \
      --exclude "logs/" \
      --exclude "cache/" \
      --exclude "tmp/" \
      --exclude "vendor/" \
      --exclude "node_modules/" \
      --exclude ".git/" \
      --exclude ".payram-core/" \
      --exclude "private_uploads/" \
      --exclude "uploads/" \
      --exclude "backups/" \
      /tmp/diparma-full-extract/ "$APP/"
    sudo chown -R www-data:www-data "$APP"
    echo "   peer.php=$(test -f $APP/api/peer.php && echo yes || echo NO)"
    echo "   SquareAdapter=$(test -f $APP/lib/Adapters/SquareAdapter.php && echo yes || echo NO)"
    echo "   square_sdk=$(test -f $APP/includes/square_sdk.php && echo yes || echo NO)"
    echo "   surplus_checkout_bank=$(test -f $APP/checkout_bank.php && echo STILL || echo deleted)"
  else
    echo "-- Skip missing $APP"
  fi
done
sudo systemctl reload php8.3-fpm 2>/dev/null || sudo systemctl reload php8.2-fpm 2>/dev/null || true
sudo systemctl reload apache2 2>/dev/null || sudo systemctl reload nginx 2>/dev/null || true
rm -rf /tmp/diparma-full-extract /tmp/diparma-full.tar.gz
echo UPLOAD_OK
'@
