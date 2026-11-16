param(
    [string]$SitemapFile = '',
    [string]$PhpCommand = 'php',
    [double]$Hours = 6,
    [int]$BatchSize = 50,
    [int]$MaxRetryWaitSeconds = 300
)

$ErrorActionPreference = 'Stop'
$serverRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$envFile = Join-Path $serverRoot '.env'
$reportFile = Join-Path $serverRoot 'crawl-report.txt'
if ($SitemapFile -eq '') {
    $SitemapFile = Join-Path $serverRoot 'tests\fixtures\koket\local\sitemap.xml'
}
if ($Hours -le 0 -or $BatchSize -lt 1 -or $MaxRetryWaitSeconds -lt 0) {
    throw 'Hours and BatchSize must be positive; MaxRetryWaitSeconds cannot be negative.'
}
$php = Get-Command $PhpCommand -ErrorAction SilentlyContinue
if ($null -eq $php) { throw 'PHP was not found. Install PHP 8.3 CLI and add it to PATH.' }
$phpPath = $php.Source
$version = & $phpPath -r 'echo PHP_MAJOR_VERSION, chr(46), PHP_MINOR_VERSION;'
if ($LASTEXITCODE -ne 0 -or $version -ne '8.3') { throw 'The full crawl requires PHP 8.3 CLI.' }
$modules = & $phpPath -m
if ($LASTEXITCODE -ne 0 -or $modules -notcontains 'pdo_mysql') {
    throw 'The full crawl requires the PHP PDO MySQL extension.'
}
if ($modules -notcontains 'openssl') {
    throw 'The full crawl requires the PHP OpenSSL extension for HTTPS.'
}
if (-not (Test-Path -LiteralPath (Join-Path $serverRoot 'vendor\autoload.php'))) {
    throw 'server/vendor is missing. Run composer install from server first.'
}
if (-not (Test-Path -LiteralPath $envFile)) {
    throw 'server/.env is missing. Copy .env.example and configure the normal database.'
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
    $settings['DB_NAME'] -notmatch '^[A-Za-z0-9_]+$' -or
    $settings['DB_NAME'].EndsWith('_pilot')) {
    throw 'The full crawl requires a local MySQL host and a DB_NAME that does not end in _pilot.'
}

function Invoke-CrawlPhp {
    param([string[]]$Arguments)
    $output = & $phpPath @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("PHP command failed: " + ($output -join [Environment]::NewLine))
    }
    try { return (($output -join [Environment]::NewLine) | ConvertFrom-Json) }
    catch { throw 'The crawler returned invalid JSON.' }
}

$overrideNames = @('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
    'CRAWL_USER_AGENT', 'MATS_ENV_FILE')
$savedOverrides = @{}
foreach ($name in $overrideNames) {
    $savedOverrides[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    [Environment]::SetEnvironmentVariable($name, $null, 'Process')
}
[Environment]::SetEnvironmentVariable('MATS_ENV_FILE', $envFile, 'Process')
Push-Location $serverRoot
try {
    $setup = Invoke-CrawlPhp -Arguments @('bin/full-setup.php')
    Write-Host ("Crawl database: {0}" -f $setup.database)
    try {
        $state = Invoke-CrawlPhp -Arguments @('bin/crawl-koket.php', 'progress')
        if (-not $state.discovery_complete) {
            $discovered = Invoke-CrawlPhp -Arguments @(
                'bin/crawl-koket.php', 'discover', '--sitemap-file', $resolvedSitemap)
            Write-Host ("Discovered {0} sitemap URLs." -f $discovered.sitemap)
            $state = Invoke-CrawlPhp -Arguments @('bin/crawl-koket.php', 'progress')
        } else {
            Write-Host 'Resuming the existing full crawl without rediscovery.'
        }

        $deadline = [DateTimeOffset]::UtcNow.AddHours($Hours)
        $lastBatchFinishedAt = [DateTimeOffset]::UtcNow
        while ([int]$state.queue.remaining -gt 0 -and [DateTimeOffset]::UtcNow -lt $deadline) {
            if ([int]$state.queue.due -gt 0) {
                $sinceLastBatch = ([DateTimeOffset]::UtcNow - $lastBatchFinishedAt).TotalSeconds
                $boundaryWait = [Math]::Max(0, [int]$state.queue.delay_s - $sinceLastBatch)
                if ($boundaryWait -gt 0) {
                    $timeLeft = [Math]::Max(0, [Math]::Floor(($deadline - [DateTimeOffset]::UtcNow).TotalSeconds))
                    Start-Sleep -Seconds ([Math]::Min([Math]::Ceiling($boundaryWait), $timeLeft))
                }
                if ([DateTimeOffset]::UtcNow -ge $deadline) { break }
                $timeLeft = ($deadline - [DateTimeOffset]::UtcNow).TotalSeconds
                $timeBoundedBatch = [Math]::Max(1, [Math]::Floor(
                    $timeLeft / [Math]::Max(1, [int]$state.queue.delay_s)))
                $batch = [Math]::Min($BatchSize, [Math]::Min([int]$state.queue.due, $timeBoundedBatch))
                $null = Invoke-CrawlPhp -Arguments @('bin/crawl-koket.php', 'fetch', [string]$batch)
                $lastBatchFinishedAt = [DateTimeOffset]::UtcNow
            } elseif ($null -ne $state.queue.next_retry_at) {
                $nextRetry = [DateTimeOffset]::Parse([string]$state.queue.next_retry_at)
                $waitSeconds = [Math]::Max(0, ($nextRetry - [DateTimeOffset]::UtcNow).TotalSeconds)
                if ($waitSeconds -gt $MaxRetryWaitSeconds) {
                    Write-Host 'Only retries more than five minutes away remain. Stopping until the next run.'
                    break
                }
                $timeLeft = [Math]::Max(0, [Math]::Floor(($deadline - [DateTimeOffset]::UtcNow).TotalSeconds))
                Start-Sleep -Seconds ([Math]::Min([Math]::Max(1, [Math]::Ceiling($waitSeconds)), $timeLeft))
            } else {
                throw 'The queue has remaining work but no due URL or scheduled retry.'
            }
            $state = Invoke-CrawlPhp -Arguments @('bin/crawl-koket.php', 'progress')
            Write-Host ("Done {0}/{1}; remaining {2}." -f
                $state.queue.done, $state.queue.total, $state.queue.remaining)
        }
        if ([DateTimeOffset]::UtcNow -ge $deadline) {
            Write-Host 'Time limit reached; stopping after the completed batch.'
        }
    } finally {
        $finalReport = Invoke-CrawlPhp -Arguments @('bin/crawl-koket.php', 'report')
        $finalReport | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $reportFile -Encoding UTF8
        Write-Host ("Crawl report saved to {0}" -f $reportFile)
    }
} finally {
    Pop-Location
    foreach ($name in $overrideNames) {
        [Environment]::SetEnvironmentVariable($name, $savedOverrides[$name], 'Process')
    }
}
