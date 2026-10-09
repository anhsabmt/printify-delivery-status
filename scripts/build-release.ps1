param([string]$Version = '1.0.4')
$ErrorActionPreference = 'Stop'
if ($Version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid version' }
$projectRoot = Split-Path $PSScriptRoot -Parent
$pluginRoot = Join-Path $projectRoot 'printify-delivery-status'
$mainFile = Join-Path $pluginRoot 'printify-delivery-status.php'
$updaterFile = Join-Path $pluginRoot 'includes\class-pds-updater.php'
if ((Get-Content -LiteralPath $mainFile -Raw) -notmatch ('Version: ' + [regex]::Escape($Version) + '\s')) { throw 'Plugin header version mismatch' }
if ((Get-Content -LiteralPath $updaterFile -Raw) -notmatch ("const VERSION = '" + [regex]::Escape($Version) + "';")) { throw 'Updater version mismatch' }
& php -l $mainFile
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' }
& php -l $updaterFile
if ($LASTEXITCODE -ne 0) { throw 'Updater lint failed' }
& php (Join-Path $projectRoot 'tests\plugin-check.php')
if ($LASTEXITCODE -ne 0) { throw 'Matching checks failed' }
& php (Join-Path $projectRoot 'tests\updater-check.php')
if ($LASTEXITCODE -ne 0) { throw 'Updater checks failed' }
& php (Join-Path $projectRoot 'tests\refund-sync-check.php')
if ($LASTEXITCODE -ne 0) { throw 'Refund sync checks failed' }
$releaseRoot = Join-Path $projectRoot 'releases'
New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipFile = Join-Path $releaseRoot "printify-delivery-status-$Version.zip"
$stream = [System.IO.File]::Open($zipFile, [System.IO.FileMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    # Only these reviewed files can enter the release ZIP.
    foreach ($relative in @('printify-delivery-status.php', 'includes/class-pds-updater.php', 'README.md')) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $pluginRoot $relative), "printify-delivery-status/$relative", [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally { $archive.Dispose(); $stream.Dispose() }
Copy-Item -LiteralPath $zipFile -Destination (Join-Path $releaseRoot 'printify-delivery-status.zip') -Force
Get-FileHash -LiteralPath $zipFile -Algorithm SHA256 | Select-Object Path, Hash
