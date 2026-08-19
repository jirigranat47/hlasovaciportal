@echo off
echo Sestavuji produkcni balicek pres Docker kontejner...
docker exec hlasovaciportal_web php /var/www/html/bin/build-release.php
pause
