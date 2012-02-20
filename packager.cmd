@echo off
php -d html_errors=off -qC "packager" %*
@echo on