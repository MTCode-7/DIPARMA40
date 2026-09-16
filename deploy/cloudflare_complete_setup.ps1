# DI PARMA | Apply complete Cloudflare zone settings for diparmas.com
# Requires: CLOUDFLARE_API_TOKEN with Zone:Read + Zone Settings:Edit + Zone Cache:Edit
# Optional: CLOUDFLARE_ZONE_ID (auto-resolved from diparmas.com if empty)
#
# Usage:
#   $env:CLOUDFLARE_API_TOKEN = 'xxxx'
#   powershell -File deploy/cloudflare_complete_setup.ps1

$ErrorActionPreference = 'Stop'
$Token = $env:CLOUDFLARE_API_TOKEN
if ([string]::IsNullOrWhiteSpace($Token)) {
    Write-Host 'MISSING CLOUDFLARE_API_TOKEN'
    Write-Host 'Create token: https://dash.cloudflare.com/profile/api-tokens'
    Write-Host 'Permissions: Zone.Zone Settings Edit, Zone.Cache Settings Edit, Zone.Zone Read'
    exit 2
}

$AccountId = '4bd36f825802c8643c95284cfad14850'
$ZoneName = 'diparmas.com'
$ZoneId = $env:CLOUDFLARE_ZONE_ID
$Headers = @{
    Authorization = "Bearer $Token"
    'Content-Type' = 'application/json'
}

function Cf-Get([string]$Url) {
    return Invoke-RestMethod -Method GET -Uri $Url -Headers $Headers
}
function Cf-Patch([string]$Url, $Body) {
    $json = if ($Body -is [string]) { $Body } else { $Body | ConvertTo-Json -Depth 8 -Compress }
    return Invoke-RestMethod -Method PATCH -Uri $Url -Headers $Headers -Body $json
}
function Cf-Put([string]$Url, $Body) {
    $json = if ($Body -is [string]) { $Body } else { $Body | ConvertTo-Json -Depth 8 -Compress }
    return Invoke-RestMethod -Method PUT -Uri $Url -Headers $Headers -Body $json
}
function Cf-Post([string]$Url, $Body) {
    $json = if ($Body -is [string]) { $Body } else { $Body | ConvertTo-Json -Depth 12 -Compress }
    return Invoke-RestMethod -Method POST -Uri $Url -Headers $Headers -Body $json
}
function Set-ZoneSetting([string]$Id, [string]$Setting, $Value) {
    $url = "https://api.cloudflare.com/client/v4/zones/$Id/settings/$Setting"
    try {
        $res = Cf-Patch $url @{ value = $Value }
        if (-not $res.success) {
            Write-Host ("SKIP setting/{0}: {1}" -f $Setting, ($res.errors | ConvertTo-Json -Compress))
            return
        }
        Write-Host ("OK setting/{0} = {1}" -f $Setting, ($Value | ConvertTo-Json -Compress))
    } catch {
        Write-Host ("SKIP setting/{0}: {1}" -f $Setting, $_.Exception.Message)
    }
}

if ([string]::IsNullOrWhiteSpace($ZoneId)) {
    $z = Cf-Get "https://api.cloudflare.com/client/v4/zones?name=$ZoneName&account.id=$AccountId"
    if (-not $z.success -or -not $z.result -or $z.result.Count -lt 1) {
        $z = Cf-Get "https://api.cloudflare.com/client/v4/zones?name=$ZoneName"
    }
    if (-not $z.success -or -not $z.result -or $z.result.Count -lt 1) {
        throw 'Zone diparmas.com not found for this token'
    }
    $ZoneId = [string]$z.result[0].id
}
Write-Host "ZONE=$ZoneId"

# Core SSL / HTTPS
Set-ZoneSetting $ZoneId 'ssl' 'strict'
Set-ZoneSetting $ZoneId 'always_use_https' 'on'
Set-ZoneSetting $ZoneId 'automatic_https_rewrites' 'on'
Set-ZoneSetting $ZoneId 'tls_1_3' 'on'
Set-ZoneSetting $ZoneId 'min_tls_version' '1.2'
Set-ZoneSetting $ZoneId 'opportunistic_encryption' 'on'
Set-ZoneSetting $ZoneId 'security_header' @{
    strict_transport_security = @{
        enabled = $true
        max_age = 31536000
        include_subdomains = $true
        preload = $true
        nosniff = $true
    }
}

# Performance safe for payments (no Rocket Loader / no HTML minify)
Set-ZoneSetting $ZoneId 'brotli' 'on'
Set-ZoneSetting $ZoneId 'http3' 'on'
Set-ZoneSetting $ZoneId 'websockets' 'on'
Set-ZoneSetting $ZoneId 'early_hints' 'on'
Set-ZoneSetting $ZoneId 'rocket_loader' 'off'
Set-ZoneSetting $ZoneId 'minify' @{ css = 'on'; html = 'off'; js = 'off' }
Set-ZoneSetting $ZoneId '0rtt' 'off'

# Caching — dynamic app; edge respects origin no-store
Set-ZoneSetting $ZoneId 'cache_level' 'standard'
Set-ZoneSetting $ZoneId 'browser_cache_ttl' 0
Set-ZoneSetting $ZoneId 'always_online' 'off'
Set-ZoneSetting $ZoneId 'development_mode' 'off'

# Security
Set-ZoneSetting $ZoneId 'security_level' 'medium'
Set-ZoneSetting $ZoneId 'challenge_ttl' 1800
Set-ZoneSetting $ZoneId 'browser_check' 'on'
Set-ZoneSetting $ZoneId 'email_obfuscation' 'off'
Set-ZoneSetting $ZoneId 'server_side_exclude' 'off'
Set-ZoneSetting $ZoneId 'hotlink_protection' 'off'
Set-ZoneSetting $ZoneId 'ip_geolocation' 'on'

# Cache Rules: bypass POS / API / admin / checkout (payment critical)
$rulesUrl = "https://api.cloudflare.com/client/v4/zones/$ZoneId/rulesets/phases/http_request_cache_settings/entrypoint"
$bypassExpr = '(starts_with(http.request.uri.path, "/pos")) or (starts_with(http.request.uri.path, "/api")) or (starts_with(http.request.uri.path, "/admin")) or (starts_with(http.request.uri.path, "/checkout")) or (http.request.uri.path eq "/checkout_router.php") or (http.request.uri.path eq "/login.php") or (http.request.uri.path eq "/pay.php") or (http.request.uri.path contains "webhook") or (http.request.uri.path contains "dmn")'
$rulesetBody = @{
    rules = @(
        @{
            action = 'set_cache_settings'
            description = 'DI PARMA — bypass cache for POS/API/admin/checkout'
            enabled = $true
            expression = $bypassExpr
            action_parameters = @{
                cache = $false
            }
        }
    )
}
try {
    $existing = Cf-Get $rulesUrl
    if ($existing.result -and $existing.result.id) {
        $putUrl = "https://api.cloudflare.com/client/v4/zones/$ZoneId/rulesets/$($existing.result.id)"
        $put = Cf-Put $putUrl $rulesetBody
        if (-not $put.success) { throw ($put.errors | ConvertTo-Json -Compress) }
        Write-Host 'OK cache_rules updated'
    } else {
        throw 'no entrypoint'
    }
} catch {
    try {
        $created = Cf-Post "https://api.cloudflare.com/client/v4/zones/$ZoneId/rulesets" (@{
            name = 'default'
            kind = 'zone'
            phase = 'http_request_cache_settings'
            rules = $rulesetBody.rules
        })
        if (-not $created.success) { throw ($created.errors | ConvertTo-Json -Compress) }
        Write-Host 'OK cache_rules created'
    } catch {
        Write-Host ("WARN cache_rules: " + $_.Exception.Message)
    }
}

# Purge everything once after settings
$purge = Cf-Post "https://api.cloudflare.com/client/v4/zones/$ZoneId/purge_cache" @{ purge_everything = $true }
if ($purge.success) { Write-Host 'OK purge_everything' } else { Write-Host 'WARN purge failed' }

Write-Host 'CLOUDFLARE_SETUP_OK'
Write-Host "Dashboard: https://dash.cloudflare.com/$AccountId/$ZoneName"
