#!/usr/bin/env bash
set -euo pipefail
host="${EVG_SSH_HOST:-tvm-live}"
wp_path="${EVG_WP_PATH:-public_html/test}"
php_bin="${EVG_PHP:-php83}"
case "$wp_path" in public_html/test|./public_html/test) ;; *) echo 'Nur public_html/test ist für diesen Test freigegeben.' >&2; exit 2;; esac
case "$php_bin" in *[!a-zA-Z0-9_-]*) echo 'Ungültiger PHP-Befehl.' >&2; exit 2;; esac
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
remote_dir="$(ssh -o BatchMode=yes "$host" 'mktemp -d /tmp/evg-group-tests.XXXXXXXX')"
if [[ ! "$remote_dir" =~ ^/tmp/evg-group-tests\.[A-Za-z0-9]+$ ]]; then echo 'Unerwarteter Testpfad.' >&2; exit 2; fi
cleanup() {
  ssh -o BatchMode=yes "$host" "rm -f '$remote_dir/class-evg-app-push.php' '$remote_dir/class-evg-group-requests.php' '$remote_dir/group-requests-staging.php'; rmdir '$remote_dir'" >/dev/null 2>&1 || true
}
trap cleanup EXIT
scp -q "$repo_dir/includes/class-evg-app-push.php" "$repo_dir/includes/class-evg-group-requests.php" "$repo_dir/tests/group-requests-staging.php" "$host:$remote_dir/"
ssh -o BatchMode=yes "$host" "cd '$wp_path' && $php_bin ~/bin/wp eval-file '$remote_dir/group-requests-staging.php' '$remote_dir'"
