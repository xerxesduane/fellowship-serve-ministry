<#
.SYNOPSIS
	Bring up the local Docker stack and leave a working site at
	http://localhost/serve.

.DESCRIPTION
	WordPress installed, the plugin activated, its two public pages created,
	the assessment on the front page, and outgoing mail captured where you can
	read it. Nothing here is a deployment target — for installing into a real
	local WordPress that already holds profiles, use tools/deploy-local.ps1
	instead, which backs the database up first.

	Safe to run repeatedly: every step checks whether it has already happened,
	so a second run reinstalls nothing and loses nothing.

	Needs Docker Desktop, and nothing else — no PHP, no MySQL, no XAMPP.

.EXAMPLE
	.\tools\dev-up.ps1

.EXAMPLE
	.\tools\dev-up.ps1 -Seed

.EXAMPLE
	.\tools\dev-up.ps1 -Reset
#>

[CmdletBinding()]
param(
	# Also seed five invented profiles, so the dashboard has content in it.
	[switch] $Seed,

	# Destroy the database and the WordPress files and start over.
	[switch] $Reset
)

$ErrorActionPreference = 'Stop'

# Compose resolves its relative paths against the file's own directory, so this
# works whichever folder it is invoked from.
Set-Location (Resolve-Path (Join-Path $PSScriptRoot '..'))

function Fail($message) {
	Write-Host ''
	Write-Host "STOPPED: $message" -ForegroundColor Red
	exit 1
}

function Invoke-Wp {
	# --rm so a run does not leave a stopped container behind; -T because
	# there is no terminal to attach when this is called from a script.
	& docker compose run --rm -T cli wp @args
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
	Fail 'Docker is not installed, or not on PATH. Install Docker Desktop: https://www.docker.com/products/docker-desktop/'
}

& docker compose version *> $null
if ($LASTEXITCODE -ne 0) { Fail 'This needs Docker Compose v2, which ships with Docker Desktop.' }

& docker info *> $null
if ($LASTEXITCODE -ne 0) { Fail 'Docker is installed but not running. Start Docker Desktop, wait for the whale to settle, and try again.' }

<#
	Read the two settings that move the stack off a busy port 80.

	Compose reads .env itself; this only needs the same values to print URLs
	that actually work, so it parses rather than requiring them in the
	environment as well.
#>
function Get-DotEnv($name, $fallback) {
	$value = [Environment]::GetEnvironmentVariable($name)
	if (-not $value -and (Test-Path '.env')) {
		$match = Select-String -Path '.env' -Pattern "^\s*$name\s*=\s*(.+?)\s*$" | Select-Object -Last 1
		if ($match) { $value = $match.Matches[0].Groups[1].Value }
	}
	if ($value) { return $value }
	return $fallback
}

$siteUrl  = Get-DotEnv 'SERVE_URL' 'http://localhost/serve'
$mailPort = Get-DotEnv 'SERVE_MAIL_PORT' '8025'

if ($Reset) {
	Write-Host '== Removing the containers and their data ==' -ForegroundColor Cyan
	# Scoped to this compose project, so it cannot reach another stack's volumes.
	& docker compose down -v
}

Write-Host '== Starting MariaDB, WordPress and Mailpit ==' -ForegroundColor Cyan
& docker compose up -d db wordpress mail
if ($LASTEXITCODE -ne 0) {
	Fail @'
Compose could not start.

   If the error above mentions a port already in use, something else on this
   machine is on port 80 — often IIS, or Skype. docs/local-docker.md has the
   two-line .env file that moves this stack out of its way.
'@
}

Write-Host '   waiting for WordPress to unpack' -NoNewline
$ready = $false
foreach ($_ in 1..60) {
	& docker compose exec -T wordpress test -f /var/www/html/serve/wp-config.php *> $null
	if ($LASTEXITCODE -eq 0) { $ready = $true; break }
	Write-Host '.' -NoNewline
	Start-Sleep -Seconds 2
}
Write-Host ''
if (-not $ready) { Fail 'WordPress never finished unpacking. `docker compose logs wordpress` says why.' }

Invoke-Wp core is-installed *> $null
if ($LASTEXITCODE -eq 0) {
	Write-Host '== WordPress is already installed - leaving it alone ==' -ForegroundColor Cyan
}
else {
	Write-Host '== Installing WordPress ==' -ForegroundColor Cyan
	Invoke-Wp core install --url=$siteUrl --title='SERVE (local)' `
		--admin_user=admin --admin_password=admin `
		--admin_email=serve-local@example.com --skip-email *> $null
	if ($LASTEXITCODE -ne 0) { Fail 'WordPress would not install. `docker compose logs db` is the usual answer.' }
	# Pretty permalinks, so the URLs here look like the ones on the real site.
	Invoke-Wp rewrite structure '/%postname%/' --hard *> $null
	Write-Host '   admin / admin' -ForegroundColor Green
}

<#
	The WordPress image writes its own .htaccess on first boot, hardcoded to
	`RewriteBase /`. This install is at /serve, so with that file the front page
	loads and every other page 404s. WP-CLI cannot fix it — writing .htaccess
	needs an Apache it can detect, and the CLI container is not one.
#>
& docker compose exec -T wordpress grep -q 'RewriteBase /serve/' /var/www/html/serve/.htaccess *> $null
if ($LASTEXITCODE -ne 0) {
	Write-Host '== Correcting the rewrite base for the subdirectory install ==' -ForegroundColor Cyan
	& docker compose cp docker/htaccess wordpress:/var/www/html/serve/.htaccess
	& docker compose exec -T -u root wordpress chown www-data:www-data /var/www/html/serve/.htaccess
}

Write-Host '== Activating the plugin ==' -ForegroundColor Cyan
Invoke-Wp plugin is-active serve-dashboard *> $null
if ($LASTEXITCODE -ne 0) {
	Invoke-Wp plugin activate serve-dashboard *> $null
	if ($LASTEXITCODE -ne 0) { Fail 'The plugin would not activate. `docker compose logs wordpress` has the PHP error.' }
}

# Activation creates the two public pages but deliberately leaves the front
# page alone, which is right for a real site and wrong for one brought up to be
# looked at.
$assessmentPage = (Invoke-Wp option get serve_dashboard_assessment_page 2>$null | Out-String).Trim()
if ($assessmentPage -match '^\d+$' -and [int] $assessmentPage -gt 0) {
	Invoke-Wp option update show_on_front page *> $null
	Invoke-Wp option update page_on_front $assessmentPage *> $null
}

if ($Seed) {
	Write-Host '== Seeding demo profiles (every name in it is invented) ==' -ForegroundColor Cyan
	Invoke-Wp eval-file wp-content/plugins/serve-dashboard/dev/seed-demo.php
}

Write-Host ''
Write-Host '== Ready ==' -ForegroundColor Green
Write-Host "   Assessment    $siteUrl/"
Write-Host "   Dashboard     $siteUrl/wp-admin/admin.php?page=serve-dashboard"
Write-Host "   Log in        $siteUrl/wp-admin/  (admin / admin)"
Write-Host "   Email         http://localhost:$mailPort  (nothing leaves your machine)"
Write-Host ''
Write-Host '   Edits to wordpress-plugin\serve-dashboard\ are live on the next request.'
Write-Host '   Stop with:  docker compose stop'
Write-Host '   Start over: .\tools\dev-up.ps1 -Reset'
Write-Host ''
