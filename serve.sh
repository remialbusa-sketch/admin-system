#!/usr/bin/env sh
# Dev server launcher with import-friendly PHP limits (see serve.cmd):
# php -S ignores the committed public/.user.ini, so the limits are passed
# as flags and no php.ini edit is needed on any machine.
if [ "$#" -eq 0 ]; then
    set -- --host=127.0.0.1 --port=8000
fi
exec php -d upload_max_filesize=256M -d post_max_size=260M -d memory_limit=512M -d max_execution_time=120 artisan serve "$@"
