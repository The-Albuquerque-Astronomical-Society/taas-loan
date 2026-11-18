#!/usr/bin/env bash
# Checks every .po file is valid and its compiled .mo file is up to date.
set -euo pipefail
status=0
for po in languages/*.po; do
	mo="${po%.po}.mo"
	echo "Checking $po"
	msgfmt --check --output-file=/dev/null "$po" || status=1
	if [ ! -f "$mo" ]; then
		echo "::error file=$po::Missing compiled file $mo (run: msgfmt -o $mo $po)"
		status=1
	elif ! diff -q <(msgunfmt "$mo") <(msgfmt --output-file=- "$po" | msgunfmt) > /dev/null; then
		echo "::error file=$mo::$mo is out of date with $po (run: msgfmt -o $mo $po)"
		status=1
	fi
done
exit $status
