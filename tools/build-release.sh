#!/usr/bin/env bash
#
# Build the installable plugin zip.
#
# The security checklist has always ended with "demo profiles deleted, and dev/
# excluded from the shipped build" — but the documented install was "copy the
# folder", which ships dev/seed-demo.php to production along with everything
# else. The seeder is WP-CLI guarded so it cannot be reached over HTTP, but a
# script whose whole job is inventing fake church members has no business on a
# live site, and neither does this file.
#
# Usage:  tools/build-release.sh
# Output: dist/serve-dashboard-<version>.zip
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
src="$root/wordpress-plugin/serve-dashboard"
out="$root/dist"

# The plugin header is the single source of truth for the version. Reading it
# here means the zip can never disagree with what WordPress reports.
version="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9.]+' "$src/serve-dashboard.php")"
if [[ -z "$version" ]]; then
  echo "Could not read Version from the plugin header." >&2
  exit 1
fi

# The header constant is what the upgrade routine compares against, so a bump
# that touches only one of the two ships a plugin that never runs its migration.
constant="$(grep -m1 -oP "SERVE_DASHBOARD_VERSION',\s*'\K[0-9.]+" "$src/serve-dashboard.php")"
if [[ "$version" != "$constant" ]]; then
  echo "Version mismatch: header says $version, SERVE_DASHBOARD_VERSION says $constant." >&2
  exit 1
fi

echo "Linting..."
lint_failed=0
while IFS= read -r file; do
  if ! php -l "$file" >/dev/null 2>&1; then
    echo "  syntax error: ${file#"$src/"}" >&2
    lint_failed=1
  fi
done < <(find "$src" -name '*.php')
[[ "$lint_failed" -eq 0 ]] || exit 1

rm -rf "$out/serve-dashboard" "$out/serve-dashboard-$version.zip"
mkdir -p "$out"

# Copy, then remove what must not ship. Excluding on the way in is easy to get
# subtly wrong; this way the contents of dist/serve-dashboard are exactly what
# a church will unzip, and can be inspected before the zip is made.
cp -r "$src" "$out/serve-dashboard"
rm -rf "$out/serve-dashboard/dev"

echo "Packaging serve-dashboard $version..."

zip_path="$out/serve-dashboard-$version.zip"

# `zip` is not present in Git Bash, which is where this will usually be run —
# the church's own machine is Windows. PowerShell always is.
if command -v zip >/dev/null 2>&1; then
  ( cd "$out" && zip -rq "serve-dashboard-$version.zip" serve-dashboard )
elif command -v powershell >/dev/null 2>&1; then
  powershell -NoProfile -NonInteractive -Command \
    "Compress-Archive -Path '$(cygpath -w "$out/serve-dashboard")' -DestinationPath '$(cygpath -w "$zip_path")' -Force"
else
  echo "Need either 'zip' or PowerShell to build the archive." >&2
  exit 1
fi

# List the archive's entries, whichever tool is to hand.
list_entries() {
  if command -v unzip >/dev/null 2>&1; then
    unzip -Z1 "$zip_path"
  else
    powershell -NoProfile -NonInteractive -Command \
      "Add-Type -AssemblyName System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::OpenRead('$(cygpath -w "$zip_path")').Entries | ForEach-Object { \$_.FullName }"
  fi
}

# Prove the exclusion held rather than trusting that the rm above ran.
entries="$(list_entries)"
if grep -q 'serve-dashboard[/\\]dev[/\\]' <<<"$entries"; then
  echo "dev/ is present in the zip — refusing to ship it." >&2
  exit 1
fi

echo
echo "dist/serve-dashboard-$version.zip"
echo "  $(grep -c '[^/\\]$' <<<"$entries") files, dev/ excluded"
