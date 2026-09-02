$content = Get-Content receipt.php -Raw
$newContent = $content -replace "`\$_COOKIE\['di_parma_lang'\]\) && `\$_COOKIE\['di_parma_lang'\] === 'en'\) \? 'en' : 'ar'", "`$_COOKIE['di_parma_lang']) && `$_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en'"
Set-Content receipt.php -Value $newContent -Encoding UTF8
