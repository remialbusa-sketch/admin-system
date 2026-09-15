@echo off
REM Dev server launcher with import-friendly PHP limits, so the import
REM wizard works on any machine without editing php.ini (php -S ignores
REM the committed public/.user.ini).
REM
REM max_execution_time MUST be 0 here. On Windows the limit is WALL-CLOCK
REM time and applies to the `artisan serve` watcher process itself, so any
REM finite value kills the whole dev server after N seconds (ServeCommand
REM polls with usleep, which counts toward it). On Linux the limit counts
REM CPU time only, which is why this bug only bites on Windows.
REM
REM Usage:  serve.cmd                              (127.0.0.1:8000)
REM         serve.cmd --host=0.0.0.0 --port=8080
set ARGS=%*
if "%ARGS%"=="" set ARGS=--host=127.0.0.1 --port=8000
php -d max_execution_time=0 -d upload_max_filesize=256M -d post_max_size=260M -d memory_limit=512M artisan serve %ARGS%
