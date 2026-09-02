$files = @(
    'capture.php',
    'checkout_diparma.php',
    'checkout_gateway.php',
    'checkout_ledger.php',
    'checkout_payram.php',
    'checkout_template.php',
    'checkout/paypal.php',
    'checkout/payram.php',
    'checkout/stripe.php',
    'create_txn.php',
    'gateway_balances.php',
    'ledger.php',
    'ledger/index.php',
    'master_wallet_setup.php',
    'nuvei_txn.php',
    'pos_download.php',
    'pos.php',
    'receipt.php'
)

foreach ($file in $files) {
    $path = Join-Path -Path (Get-Location) -ChildPath $file
    if (Test-Path $path) {
        $content = Get-Content $path -Raw -Encoding UTF8
        $newContent = $content -replace "\`$_COOKIE\['di_parma_lang'\]\) && \$_COOKIE\['di_parma_lang'\] === 'en'\) \? 'en' : 'ar'", "`$_COOKIE['di_parma_lang']) && `$_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en'"
        Set-Content $path -Value $newContent -Encoding UTF8
        Write-Host "✓ $file"
    } else {
        Write-Host "✗ $file not found"
    }
}
