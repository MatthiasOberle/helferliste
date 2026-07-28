@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ============================================================
REM Helferliste - Windows Server / XAMPP Installationshilfe
REM ============================================================
REM Erwartete Paketstruktur:
REM   README.md
REM   www\
REM   Install\install_helferliste_windows.bat
REM   Install\database_schema.sql
REM   Install\migrate.php
REM   Install\migration_lib.php
REM   Install\migrations\001_baseline.sql
REM
REM Das Script kopiert die Webdateien, erstellt die SQLite-
REM Datenbank und setzt einfache Rechte. PHP wird nicht
REM automatisch installiert.
REM ============================================================

net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo [FEHLER] Bitte diese Datei als Administrator ausfuehren.
  pause
  exit /b 1
)

set "SCRIPT_DIR=%~dp0"
for %%I in ("%SCRIPT_DIR%..") do set "PACKAGE_ROOT=%%~fI"
set "WEB_SOURCE=%PACKAGE_ROOT%\www"
set "APP_ROOT=C:\inetpub\helferliste"
set "PUBLIC_DIR=%APP_ROOT%\public"
set "DATA_DIR=%APP_ROOT%\data"
set "DB_FILE=%DATA_DIR%\helferliste.sqlite"
set "PHP_EXE=php.exe"

cls
echo Helferliste Installation fuer Windows Server / XAMPP
echo ----------------------------------------------------
echo.
echo Empfohlen fuer Einsteiger: XAMPP oder IIS mit installiertem PHP 8.3 oder neuer.
echo Dieses Script richtet die App-Dateien ein. PHP mit pdo_sqlite muss bereits funktionieren.
echo.
set /p APP_ROOT="Installationsordner [%APP_ROOT%]: "
if "%APP_ROOT%"=="" set "APP_ROOT=C:\inetpub\helferliste"
set "PUBLIC_DIR=%APP_ROOT%\public"
set "DATA_DIR=%APP_ROOT%\data"
set "DB_FILE=%DATA_DIR%\helferliste.sqlite"

set /p PHP_EXE="Pfad zu php.exe, falls nicht im PATH [php.exe]: "
if "%PHP_EXE%"=="" set "PHP_EXE=php.exe"

echo.
echo [INFO] Aktiviere IIS-Grundfunktionen, falls vorhanden ...
dism /online /enable-feature /featurename:IIS-WebServerRole /all /norestart >nul 2>&1
dism /online /enable-feature /featurename:IIS-WebServer /all /norestart >nul 2>&1
dism /online /enable-feature /featurename:IIS-CGI /all /norestart >nul 2>&1

echo [INFO] Erstelle Ordner ...
if not exist "%PUBLIC_DIR%" mkdir "%PUBLIC_DIR%"
if not exist "%DATA_DIR%" mkdir "%DATA_DIR%"

if exist "%WEB_SOURCE%" (
  echo [INFO] Kopiere Webdateien aus "%WEB_SOURCE%" ...
  robocopy "%WEB_SOURCE%" "%PUBLIC_DIR%" /E /NFL /NDL /NJH /NJS /NP >nul
  if errorlevel 8 (
    echo [FEHLER] Dateien konnten nicht kopiert werden.
    pause
    exit /b 1
  )
) else (
  echo [FEHLER] Der Ordner "www" wurde neben dem Ordner "Install" nicht gefunden.
  echo Bitte das komplette aktuelle Paket verwenden.
  pause
  exit /b 1
)

if not exist "%SCRIPT_DIR%migrate.php" (
  echo [FEHLER] migrate.php wurde nicht gefunden.
  pause
  exit /b 1
)

echo [INFO] Erstelle oder migriere Datenbank ueber PHP ...
"%PHP_EXE%" "%SCRIPT_DIR%migrate.php" "%DB_FILE%" "%DATA_DIR%\backups"
if not "%errorlevel%"=="0" (
  echo.
  echo [FEHLER] Datenbank konnte nicht erstellt werden.
  echo Pruefe, ob PHP installiert ist und ob pdo_sqlite in der php.ini aktiv ist.
  echo Typischer Test: php -m ^| findstr sqlite
  pause
  exit /b 1
)

echo [INFO] Pruefe PHP-Grenzen fuer Bild-Uploads ...
"%PHP_EXE%" -r "exit(((int)ini_get('upload_max_filesize') >= 8 && (int)ini_get('post_max_size') >= 10) ? 0 : 1);"
if errorlevel 1 (
  echo [HINWEIS] Fuer Bilder bis 8 MB bitte in der verwendeten php.ini setzen:
  echo           upload_max_filesize = 8M
  echo           post_max_size = 10M
  echo           Danach Apache, IIS beziehungsweise PHP-FastCGI neu starten.
)

echo [INFO] Setze Rechte fuer IIS ...
icacls "%APP_ROOT%" /grant "IIS_IUSRS:(OI)(CI)(RX)" /T >nul
icacls "%DATA_DIR%" /grant "IIS_IUSRS:(OI)(CI)(M)" /T >nul
icacls "%DATA_DIR%" /grant "IUSR:(OI)(CI)(M)" /T >nul 2>&1
if not exist "%PUBLIC_DIR%\assets\uploads" mkdir "%PUBLIC_DIR%\assets\uploads"
icacls "%PUBLIC_DIR%\assets\uploads" /grant "IIS_IUSRS:(OI)(CI)(M)" /T >nul
icacls "%PUBLIC_DIR%\assets\uploads" /grant "IUSR:(OI)(CI)(M)" /T >nul 2>&1

echo.
echo ============================================================
echo Fertig.
echo ============================================================
echo Installationsordner: %APP_ROOT%
echo Webroot:             %PUBLIC_DIR%
echo Datenbank:           %DB_FILE%
echo.
echo Naechste Schritte fuer IIS:
echo 1. Im IIS-Manager eine neue Website anlegen.
echo 2. Physischer Pfad: %PUBLIC_DIR%
echo 3. Bindung: deine Domain oder IP eintragen.
echo 4. PHP/FastCGI pruefen. Falls web.config einen anderen PHP-Pfad braucht,
echo    bitte public\web.config anpassen.
echo 5. Admin oeffnen: http://DEINE-DOMAIN/admin.php
echo 6. Login: admin / GetYourOwnWebsite
echo 7. Direkt das Passwort aendern.
echo.
echo Hinweis fuer XAMPP:
echo Du kannst als Installationsordner auch C:\xampp\htdocs\helferliste nehmen.
echo Dann oeffnest du http://localhost/helferliste/public/admin.php
echo.
pause
