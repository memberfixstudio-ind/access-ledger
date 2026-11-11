#!/usr/bin/env bash
set -euo pipefail
# Run from the disposable WordPress root with wp on PATH.
plugin_dir="$(cd "$(dirname "$0")/.." && pwd)"
barrier="$(mktemp)"
rm "$barrier"
trap 'rm -f "$barrier"' EXIT
export AL_TEST_BARRIER="$barrier"
AL_TEST_MODE=setup wp eval-file "$plugin_dir/tests/concurrency.php"
AL_TEST_MODE=repair wp eval-file "$plugin_dir/tests/concurrency.php" &
worker=$!
for ((i=0;i<100;i++)); do
  [[ -f "$barrier" ]] && break
  sleep 0.1
done
[[ -f "$barrier" ]] || { wait "$worker"; exit 1; }
AL_TEST_MODE=consume wp eval-file "$plugin_dir/tests/concurrency.php"
wait "$worker"
AL_TEST_MODE=verify wp eval-file "$plugin_dir/tests/concurrency.php"
