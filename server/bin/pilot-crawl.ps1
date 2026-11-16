param(
    [string]$SitemapFile = '',
    [string]$PhpCommand = 'php',
    [int]$SampleSize = 500,
    [int]$BatchSize = 50,
    [int]$MaxRetryWaitSeconds = 300
)

$ErrorActionPreference = 'Stop'
$serverRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$envFile = Join-Path $serverRoot '.env'
$vendorFile = Join-Path $serverRoot 'vendor\autoload.php'
$reportFile = Join-Path $serverRoot 'pilot-report.txt'
if ($SitemapFile -eq '') {
    $SitemapFile = Join-Path $serverRoot 'tests\fixtures\koket\local\sitemap.xml'
}

if ($SampleSize -lt 1 -or $BatchSize -lt 1 -or $MaxRetryWaitSeconds -lt 0) {
    throw 'SampleSize and BatchSize must be positive; MaxRetryWaitSeconds cannot be negative.'
}
$php = Get-Command $PhpCommand -ErrorAction SilentlyContinue
if ($null -eq $php) {
    throw 'PHP was not found. Install PHP 8.3 CLI and add it to PATH.'
}
$phpPath = $php.Source
$version = & $phpPath -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'
if ($LASTEXITCODE -ne 0 -or $version -ne '8.3') {
    throw 'The pilot requires PHP 8.3 CLI.'
}
$pdoMysql = & $phpPath -r 'echo extension_loaded("pdo_mysql") ? "yes" : "no";'
if ($LASTEXITCODE -ne 0 -or $pdoMysql -ne 'yes') {
    throw 'The pilot requires the PHP PDO MySQL extension.'
}
if (-not (Test-Path -LiteralPath $vendorFile)) {
    throw 'server/vendor is missing. Run composer install from server first.'
}
if (-not (Test-Path -LiteralPath $envFile)) {
    throw 'server/.env is missing. Copy .env.example and configure the pilot database and CRAWL_USER_AGENT.'
}
if (-not (Test-Path -LiteralPath $SitemapFile)) {
    throw "Local sitemap file is missing: $SitemapFile"
}
$resolvedSitemap = (Resolve-Path -LiteralPath $SitemapFile).Path

$settings = @{}
foreach ($line in (Get-Content -LiteralPath $envFile)) {
    if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$') {
        $settings[$Matches[1]] = $Matches[2].Trim().Trim('"', "'")
    }
}
if ([string]::IsNullOrWhiteSpace($settings['CRAWL_USER_AGENT'])) {
    throw 'Set CRAWL_USER_AGENT in server/.env to an identifying value with a contact address.'
}
if ($settings['DB_HOST'] -notin @('localhost', '127.0.0.1') -or
    $settings['DB_NAME'] -notmatch '^[A-Za-z0-9_]+_pilot$') {
    throw 'The pilot requires a local MySQL host and a separate DB_NAME ending in _pilot.'
}

function Invoke-PilotPhp {
    param([string[]]$Arguments)
    $output = & $phpPath @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("PHP command failed: " + ($output -join [Environment]::NewLine))
    }
    try {
        return (($output -join [Environment]::NewLine) | ConvertFrom-Json)
    } catch {
        throw 'The crawler returned invalid JSON.'
    }
}

$overrideNames = @('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'CRAWL_USER_AGENT', 'MATS_ENV_FILE')
$savedOverrides = @{}
foreach ($name in $overrideNames) {
    $savedOverrides[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    [Environment]::SetEnvironmentVariable($name, $null, 'Process')
}
[Environment]::SetEnvironmentVariable('MATS_ENV_FILE', $envFile, 'Process')

Push-Location $serverRoot
try {
    $setup = Invoke-PilotPhp -Arguments @('bin/pilot-setup.php')
    Write-Host ("Pilot database: {0}" -f $setup.database)

    $state = Invoke-PilotPhp -Arguments @('bin/crawl-koket.php', 'report')
    if ([int]$state.queue.total -eq 0) {
        $sample = Invoke-PilotPhp -Arguments @(
            'bin/crawl-koket.php', 'discover', '--sample', [string]$SampleSize,
            '--sitemap-file', $resolvedSitemap
        )
        Write-Host ("Sampled {0} URLs ({1} root, {2} nested)." -f $sample.sampled, $sample.root, $sample.nested)
        $state = Invoke-PilotPhp -Arguments @('bin/crawl-koket.php', 'report')
    } else {
        Write-Host 'Resuming the existing pilot queue without re-sampling.'
    }

    $lastBatchFinishedAt = [DateTimeOffset]::UtcNow
    while ([int]$state.queue.remaining -gt 0) {
        if ([int]$state.queue.due -gt 0) {
            if ($null -ne $lastBatchFinishedAt) {
                $sinceLastBatch = ([DateTimeOffset]::UtcNow - $lastBatchFinishedAt).TotalSeconds
                $boundaryWait = [Math]::Max(0, [int]$state.queue.delay_s - $sinceLastBatch)
                if ($boundaryWait -gt 0) {
                    Start-Sleep -Seconds ([Math]::Ceiling($boundaryWait))
                }
            }
            $batch = [Math]::Min($BatchSize, [int]$state.queue.due)
            $null = Invoke-PilotPhp -Arguments @('bin/crawl-koket.php', 'fetch', [string]$batch)
            $lastBatchFinishedAt = [DateTimeOffset]::UtcNow
        } elseif ($null -ne $state.queue.next_retry_at) {
            $nextRetry = [DateTimeOffset]::Parse([string]$state.queue.next_retry_at)
            $waitSeconds = [Math]::Max(0, ($nextRetry - [DateTimeOffset]::UtcNow).TotalSeconds)
            if ($waitSeconds -gt $MaxRetryWaitSeconds) {
                Write-Host ("Only retries more than {0} seconds away remain. Stopping until the next run." -f
                    $MaxRetryWaitSeconds)
                break
            }
            Start-Sleep -Seconds ([Math]::Max(1, [Math]::Ceiling($waitSeconds)))
        } else {
            throw 'The queue has remaining work but no due URL or scheduled retry.'
        }
        $state = Invoke-PilotPhp -Arguments @('bin/crawl-koket.php', 'report')
        $eta = [TimeSpan]::FromSeconds([int]$state.queue.remaining * [int]$state.queue.delay_s)
        Write-Host ("Done {0}/{1}; remaining {2}; estimated fetch time {3:hh\:mm\:ss}." -f
            $state.queue.done, $state.queue.total, $state.queue.remaining, $eta)
    }

    $finalReport = Invoke-PilotPhp -Arguments @('bin/crawl-koket.php', 'report')
    $finalReport | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $reportFile -Encoding UTF8
    Write-Host ("Pilot report saved to {0}" -f $reportFile)
} finally {
    Pop-Location
    foreach ($name in $overrideNames) {
        [Environment]::SetEnvironmentVariable($name, $savedOverrides[$name], 'Process')
    }
}
