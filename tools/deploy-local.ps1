<#
.SYNOPSIS
	Install this working tree's plugin into a local WordPress, safely.

.DESCRIPTION
	For a development install that holds real profiles — which is the case this
	exists for. A localhost WordPress with a congregation's answers in it is not
	production by any declaration, and nothing in the plugin or the test runner
	can tell the difference on its own, so the care has to live in the procedure.

	It takes a database backup first and stops if the backup did not work. An
	upgrade that cannot be undone is not one to start.

	What it does NOT do: activate the plugin, run the schema upgrade, seed demo
	data, or run the test suite. Activation is the step that migrates the
	database, so it stays a decision somebody takes in the admin screen with the
	backup path in front of them.

.EXAMPLE
	.\tools\deploy-local.ps1 -WordPressPath C:\xampp\htdocs\serve -DbName serve_db

.EXAMPLE
	.\tools\deploy-local.ps1 -WordPressPath C:\laragon\www\serve -DbName serve_db -DbUser root -Link
#>

[CmdletBinding()]
param(
	# The WordPress root — the folder holding wp-config.php.
	[Parameter(Mandatory = $true)]
	[string] $WordPressPath,

	# The WordPress database to back up before anything is copied.
	[Parameter(Mandatory = $true)]
	[string] $DbName,

	[string] $DbUser = 'root',

	# Prompted for if the database needs one. Not a parameter default, so it
	# never ends up in shell history.
	[string] $DbPassword,

	# Where the backup goes. Defaults beside the project.
	[string] $BackupDir = "$PSScriptRoot\..\..\serve-backups",

	# Junction the plugin folder to this working tree instead of copying it, so
	# edits appear immediately. Development only.
	[switch] $Link,

	# Skip the backup. Only for an install whose contents you are willing to
	# lose — a scratch database, or one holding nothing but demo accounts.
	# Deliberately opt-in: the safe path should be the one you get by default.
	[switch] $SkipBackup
)

$ErrorActionPreference = 'Stop'

function Fail($message) {
	Write-Host ''
	Write-Host "STOPPED: $message" -ForegroundColor Red
	exit 1
}

$plugin = Resolve-Path "$PSScriptRoot\..\wordpress-plugin\serve-dashboard" -ErrorAction SilentlyContinue
if (-not $plugin) { Fail 'Could not find wordpress-plugin\serve-dashboard. Run this from inside the project.' }

if (-not (Test-Path "$WordPressPath\wp-config.php")) {
	Fail "No wp-config.php in $WordPressPath. That does not look like a WordPress root."
}

$pluginsDir = Join-Path $WordPressPath 'wp-content\plugins'
if (-not (Test-Path $pluginsDir)) { Fail "No wp-content\plugins in $WordPressPath." }

$stamp  = Get-Date -Format 'yyyy-MM-dd-HHmmss'
$backup = $null

# ---------------------------------------------------------------- 1. Backup

if ($SkipBackup) {
	Write-Host '== Skipping the backup (-SkipBackup) ==' -ForegroundColor Yellow
	Write-Host '   Nothing here can be undone. Correct only for demo or scratch data.'
}
else {

Write-Host '== Backing up the database ==' -ForegroundColor Cyan

if (-not (Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir | Out-Null }

$backup = Join-Path $BackupDir "$DbName-before-serve-dashboard-$stamp.sql"

<#
	Resolve to a plain path string.

	Get-Command returns a CommandInfo, whose executable path is .Source.
	Get-Item returns a FileInfo, which has no .Source at all — so the fallback
	branch produced an object that read as found and then invoked as an empty
	path. The two shapes have to be flattened here rather than at the call site.
#>
$dumpExe = $null

$onPath = Get-Command mysqldump -ErrorAction SilentlyContinue
if ($onPath) {
	$dumpExe = $onPath.Source
}
else {
	foreach ($guess in @(
		'C:\xampp\mysql\bin\mysqldump.exe',
		'C:\laragon\bin\mysql\*\bin\mysqldump.exe',
		'C:\Program Files\MySQL\*\bin\mysqldump.exe'
	)) {
		$found = Get-Item $guess -ErrorAction SilentlyContinue | Select-Object -First 1
		if ($found) { $dumpExe = $found.FullName; break }
	}
}

if (-not $dumpExe -or -not (Test-Path $dumpExe)) {
	Fail 'Could not find mysqldump. Add it to PATH, or back the database up by hand first.'
}

if (-not $DbPassword) {
	$secure = Read-Host "Password for MySQL user '$DbUser' (blank if none)" -AsSecureString
	$DbPassword = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
		[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
	)
}

$dumpArgs = @("--user=$DbUser")
if ($DbPassword) { $dumpArgs += "--password=$DbPassword" }
$dumpArgs += @('--single-transaction', '--routines', $DbName)

<#
	Redirected by cmd rather than by PowerShell.

	Windows PowerShell 5.1's Out-File -Encoding utf8 writes a byte-order mark,
	and a BOM at the head of a .sql file makes `mysql < backup.sql` fail on the
	first statement — so the backup would restore into an error at exactly the
	moment it was needed. Letting cmd do the redirection keeps the bytes as
	mysqldump emitted them.
#>
$quoted = ($dumpArgs | ForEach-Object { '"' + $_ + '"' }) -join ' '
& cmd /c "`"$dumpExe`" $quoted > `"$backup`""

# An empty or tiny file means the dump failed however it exited.
if (-not (Test-Path $backup) -or (Get-Item $backup).Length -lt 1024) {
	Fail "The backup at $backup is empty or missing. Nothing has been changed."
}

$size = [math]::Round((Get-Item $backup).Length / 1MB, 2)
Write-Host "   backup written: $backup ($size MB)" -ForegroundColor Green

}

# ------------------------------------------------------- 2. Install the code

Write-Host ''
Write-Host '== Installing the plugin ==' -ForegroundColor Cyan

$target = Join-Path $pluginsDir 'serve-dashboard'

if (Test-Path $target) {
	$item = Get-Item $target -Force
	if ($item.LinkType) {
		Write-Host "   removing existing junction at $target"
		$item.Delete()
	}
	else {
		# Keep the old copy rather than overwriting it, so a bad release can be
		# put back by renaming a folder.
		$kept = "$target-previous-$stamp"
		Write-Host "   moving the existing plugin aside: $kept"
		Move-Item $target $kept
	}
}

if ($Link) {
	New-Item -ItemType Junction -Path $target -Target $plugin | Out-Null
	Write-Host "   junctioned $target -> $plugin" -ForegroundColor Green
	Write-Host '   edits in the working tree now apply immediately.'
}
else {
	Copy-Item $plugin $target -Recurse
	# dev/ seeds fictional people and rewrites team headcounts. It has no place
	# next to real data, and the release zip excludes it for the same reason.
	$dev = Join-Path $target 'dev'
	if (Test-Path $dev) { Remove-Item $dev -Recurse -Force }
	Write-Host "   copied to $target (dev/ excluded)" -ForegroundColor Green
}

# --------------------------------------------------------------- 3. Hand off

$headerMatch = Select-String -Path (Join-Path $plugin 'serve-dashboard.php') -Pattern '^\s*\*\s*Version:\s*(.+)$' | Select-Object -First 1
$version = if ($headerMatch) { $headerMatch.Matches[0].Groups[1].Value.Trim() } else { 'unknown' }

Write-Host ''
Write-Host '== Next, by hand ==' -ForegroundColor Cyan
Write-Host "   Plugin $version is in place but has NOT been activated."
Write-Host '   Activating is what runs the database upgrade, so it is left to you.'
Write-Host ''
Write-Host '   1. Log in to wp-admin and activate SERVE Dashboard.'
Write-Host '   2. Check SERVE -> Teams and gaps: the crosswalk audit lists any'
Write-Host '      ministry word the assessment cannot measure.'
Write-Host '   3. Existing profiles will say they predate the snapshot record.'
Write-Host '      That is correct: they were matched under the old rules.'
Write-Host '   4. For a read-only view of legacy match-created placements:'
Write-Host '         wp eval-file wp-content/plugins/serve-dashboard/../../../tools/reconcile-placements.php'
Write-Host '      It reports; it deletes nothing.'
Write-Host ''
Write-Host '   tools/run-tests.php writes to whatever it is pointed at, and now' -ForegroundColor Yellow
Write-Host '   refuses a database already holding submissions. If this install is' -ForegroundColor Yellow
Write-Host '   demo data you are willing to lose, add SERVE_TEST_ALLOW_EXISTING=1.' -ForegroundColor Yellow
if ($backup) {
	Write-Host "   To undo: mysql -u $DbUser $DbName < `"$backup`"" -ForegroundColor Yellow
}
Write-Host ''
