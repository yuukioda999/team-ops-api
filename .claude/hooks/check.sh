#!/usr/bin/env bash
# Stop: run the test suite before Claude finishes. Exit 2 makes Claude keep working on failures.
[ -d vendor ] || exit 0
if ! out=$(php artisan test --stop-on-failure 2>&1); then
  echo "Tests are failing. Fix them before finishing:" >&2
  echo "$out" | tail -40 >&2
  exit 2
fi
exit 0
