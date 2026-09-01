#!/usr/bin/env bash
#
# The plugin's dashboard and the standalone one must look identical.
#
# That is not achieved by matching them by eye. It is achieved by rendering the
# same markup against the same stylesheet -- so the guarantee holds exactly as
# long as the files stay identical, and nothing was checking that.
#
# A copy that silently drifts is worse than an obvious fork: the two builds
# diverge one edit at a time, and the first person to notice is whoever opens
# both on the same day.
#
# If this fails, do not "fix" it by copying one over the other without looking.
# Decide which side the change belongs on, and whether it belongs on both.

set -euo pipefail

plugin="wordpress-plugin/serve-dashboard"
standalone="serve-standalone"

fail=0

check() {
	local a="$1" b="$2"

	if [ ! -f "$a" ]; then echo "  missing: $a"; fail=1; return; fi
	if [ ! -f "$b" ]; then echo "  missing: $b"; fail=1; return; fi

	if diff -q "$a" "$b" >/dev/null; then
		printf '  same        %s\n' "${a#"$plugin"/}"
	else
		printf '  DIFFERS     %s\n' "${a#"$plugin"/}"
		diff -u "$a" "$b" | head -40
		fail=1
	fi
}

echo "Shared front-end files:"

# The dashboard's stylesheet and module, and the participant journey. Every one
# of these is loaded unmodified by both builds.
check "$plugin/admin/css/app.css"   "$standalone/public/admin/css/app.css"
check "$plugin/admin/css/admin.css" "$standalone/public/admin/css/admin.css"
check "$plugin/admin/js/app.js"     "$standalone/public/admin/js/app.js"
check "$plugin/admin/js/admin.js"   "$standalone/public/admin/js/admin.js"

for f in "$plugin"/public/assessment/*; do
	check "$f" "$standalone/public/assessment/$(basename "$f")"
done

# The dashboard shell markup, which may differ only by the direct-access guard
# WordPress needs and this build does not.
echo
echo "Dashboard shell (only the ABSPATH guard may differ):"

shell_diff=$(diff "$plugin/admin/views/app.php" "$standalone/views/app/shell.php" || true)
guard_only=$(printf '%s\n' "$shell_diff" | grep -vE "^(---|[0-9,]+d[0-9]+|< $|< if \( ! defined\( 'ABSPATH' \) \) \{|< \s*exit;|< \})" || true)

if [ -z "$guard_only" ]; then
	echo "  same, apart from the guard"
else
	echo "  DIFFERS beyond the guard:"
	printf '%s\n' "$shell_diff" | head -40
	fail=1
fi

echo

if [ "$fail" -ne 0 ]; then
	echo "The two builds' front ends have diverged. See the notes at the top of this file."
	exit 1
fi

echo "The two builds share one front end."
