param(
    [Parameter(Position=0)][ValidateSet('scan','run')][string]$Mode = 'scan',
    [Parameter(Position=1)][string]$Name = 'backup',
    [Parameter(Position=2)][string]$Path = '.',
    [Parameter(ValueFromRemainingArguments=$true)][string[]]$Command
)

$ErrorActionPreference = 'Stop'

if (-not $env:PINGME_URL -or -not $env:PINGME_TOKEN) {
    Write-Error 'PINGME_URL and PINGME_TOKEN are required'
    exit 2
}

function Send-PingMeBackup([hashtable]$Payload) {
    $headers = @{ Authorization = "Bearer $($env:PINGME_TOKEN)" }
    $body = $Payload | ConvertTo-Json -Depth 5 -Compress
    Invoke-RestMethod -Method Post -Uri "$($env:PINGME_URL.TrimEnd('/'))/api/v1/ingest/backups" -Headers $headers -ContentType 'application/json' -Body $body | Out-Null
}

function Get-BackupScan([string]$State) {
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) {
        throw "Backup directory does not exist: $Path"
    }

    $files = @(Get-ChildItem -LiteralPath $Path -File -Recurse)
    $oldest = $files | Sort-Object LastWriteTimeUtc | Select-Object -First 1
    $newest = $files | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1

    return @{
        event_uuid = [guid]::NewGuid().ToString()
        name = $Name
        state = $State
        files_count = $files.Count
        total_size_bytes = [long](($files | Measure-Object Length -Sum).Sum)
        newest_size_bytes = if ($newest) { [long]$newest.Length } else { 0 }
        oldest_backup_at = if ($oldest) { $oldest.LastWriteTimeUtc.ToString('o') } else { $null }
        newest_backup_at = if ($newest) { $newest.LastWriteTimeUtc.ToString('o') } else { $null }
        recorded_at = [DateTime]::UtcNow.ToString('o')
    }
}

if ($Mode -eq 'scan') {
    Send-PingMeBackup (Get-BackupScan 'scan')
    exit 0
}

Send-PingMeBackup @{ event_uuid = [guid]::NewGuid().ToString(); name = $Name; state = 'started'; recorded_at = [DateTime]::UtcNow.ToString('o') }
$started = Get-Date
$commandLine = ($Command -join ' ').TrimStart('-', ' ')
cmd.exe /d /s /c $commandLine
$exitCode = $LASTEXITCODE
$duration = [int]((Get-Date) - $started).TotalSeconds

if ($exitCode -eq 0) {
    $payload = Get-BackupScan 'success'
    $payload.duration_seconds = $duration
    Send-PingMeBackup $payload
} else {
    Send-PingMeBackup @{ event_uuid = [guid]::NewGuid().ToString(); name = $Name; state = 'failure'; duration_seconds = $duration; message = "Exit code $exitCode"; recorded_at = [DateTime]::UtcNow.ToString('o') }
}

exit $exitCode
