#!/usr/bin/env bash
set -euo pipefail
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
remote_dir="$(ssh -o BatchMode=yes tvm-live 'mktemp -d /tmp/evg-event-tests.XXXXXXXX')"
[[ "$remote_dir" =~ ^/tmp/evg-event-tests\.[A-Za-z0-9]+$ ]] || exit 2
cleanup() { ssh -o BatchMode=yes tvm-live "rm -f '$remote_dir/class-evg-events-sync.php' '$remote_dir/class-evg-event-writes.php' '$remote_dir/class-evg-training-calendars.php' '$remote_dir/event-creations-staging.php'; rmdir '$remote_dir'" >/dev/null 2>&1 || true; }
trap cleanup EXIT
scp -q "$repo_dir/includes/class-evg-events-sync.php" "$repo_dir/includes/class-evg-event-writes.php" "$repo_dir/includes/class-evg-training-calendars.php" "$repo_dir/tests/event-creations-staging.php" "tvm-live:$remote_dir/"
ssh -o BatchMode=yes tvm-live "cd public_html/test && php83 ~/bin/wp --skip-plugins --skip-themes eval-file '$remote_dir/event-creations-staging.php' '$remote_dir'"
