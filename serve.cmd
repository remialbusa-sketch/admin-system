@echo off
REM Dev server launcher with import-friendly PHP limits, so the import
REM wizard works on any machine without editing php.ini (php -S ignores
REM the committed public/.user.ini).
REM
REM Usage:  serve.cmd                              (127.0.0.1:8000)
REM         serve.cmd --host=0.0.0.0 --port=8080
set ARGS=%*
if "%ARGS%"=="" set ARGS=--host=127.0.0.1 --port=8000
php -d upload_max_filesize=256M -d post_max_size=260M -d memory_limit=512M -d max_execution_time=120 artisan serve %ARGS%
