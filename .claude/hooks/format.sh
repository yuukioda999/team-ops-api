#!/usr/bin/env bash
# PostToolUse: format the PHP file Claude just edited.
FILE=$(python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get(\"tool_input\",{}).get(\"file_path\",\"\"))" 2>/dev/null || true)
case "$FILE" in
  *.php) [ -x vendor/bin/pint ] && vendor/bin/pint --quiet "$FILE" ;;
esac
exit 0
