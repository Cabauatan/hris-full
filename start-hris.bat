@echo off
setlocal EnableExtensions DisableDelayedExpansion

for %%I in ("%~dp0.") do set "HRIS_BACKEND=%%~fI"
for %%I in ("%~dp0..\hris-frontend") do set "HRIS_FRONTEND=%%~fI"
set "HRIS_PHP="
set "HRIS_NODE="
set "HRIS_RESULT=0"
set "HRIS_NO_PAUSE="
set "HRIS_CHECK_ONLY="

if /I "%~1"=="--no-pause" set "HRIS_NO_PAUSE=1"
if /I "%~1"=="--check" set "HRIS_CHECK_ONLY=1"
if defined HRIS_CHECK_ONLY set "HRIS_NO_PAUSE=1"

if not exist "%HRIS_BACKEND%\artisan" (
    echo [ERROR] Laravel artisan was not found beside this launcher.
    goto failed
)
if not exist "%HRIS_BACKEND%\vendor\autoload.php" (
    echo [ERROR] Backend dependencies are missing. Run composer install first.
    goto failed
)
if not exist "%HRIS_BACKEND%\.env" (
    echo [ERROR] The backend .env file is missing.
    goto failed
)
if not exist "%HRIS_FRONTEND%\node_modules\vite\bin\vite.js" (
    echo [ERROR] Frontend dependencies are missing in "%HRIS_FRONTEND%".
    echo Run npm install in the frontend folder first.
    goto failed
)

if exist "%USERPROFILE%\.config\herd\bin\php85\php.exe" set "HRIS_PHP=%USERPROFILE%\.config\herd\bin\php85\php.exe"
if not defined HRIS_PHP for /F "delims=" %%I in ('where php 2^>nul') do if not defined HRIS_PHP set "HRIS_PHP=%%I"
for /F "delims=" %%I in ('where node.exe 2^>nul') do if not defined HRIS_NODE set "HRIS_NODE=%%I"

if not defined HRIS_PHP (
    echo [ERROR] PHP was not found. Enable PHP in Herd or add PHP to PATH.
    goto failed
)
if not defined HRIS_NODE (
    echo [ERROR] Node.js was not found. Add Node.js to PATH.
    goto failed
)
call "%HRIS_PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP could not run.
    goto failed
)
"%HRIS_NODE%" --version >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Node.js could not run.
    goto failed
)

if /I "%~1"=="--backend" goto run_backend
if /I "%~1"=="--frontend" goto run_frontend
if /I "%~1"=="--reverb" goto run_reverb
if /I "%~1"=="--queue" goto run_queue
if /I "%~1"=="--telegram" goto run_telegram

echo HRIS development launcher
"%HRIS_NODE%" --env-file="%HRIS_BACKEND%\.env" -e "const backend = new URL(process.env.APP_URL); const frontend = new URL(process.env.FRONTEND_URL); const reverb = new URL((process.env.REVERB_SCHEME === 'https' ? 'wss://' : 'ws://') + process.env.REVERB_HOST + ':' + (process.env.REVERB_PORT || '8082')); console.log('Backend:  ' + backend.origin); console.log('Frontend: ' + frontend.origin); console.log('Reverb:   ' + reverb.origin);"
if errorlevel 1 goto failed
echo Queue:    notifications
echo Telegram: HRIS bot commands when configured
echo.

call :port_available 8002
if errorlevel 2 goto port_check_failed
if errorlevel 1 (
    echo [SKIP] Port 8002 is already in use. The existing process was left running.
) else (
    if defined HRIS_CHECK_ONLY (
        echo [CHECK] Backend port 8002 is available.
    ) else (
        start "HRIS Backend - 8002" /D "%HRIS_BACKEND%" "%ComSpec%" /D /K call "%~f0" --backend
        if errorlevel 1 goto failed
        echo [START] Backend server window opened.
    )
)

call :port_available 5002
if errorlevel 2 goto port_check_failed
if errorlevel 1 (
    echo [SKIP] Port 5002 is already in use. The existing process was left running.
) else (
    if defined HRIS_CHECK_ONLY (
        echo [CHECK] Frontend port 5002 is available.
    ) else (
        start "HRIS Frontend - 5002" /D "%HRIS_FRONTEND%" "%ComSpec%" /D /K call "%~f0" --frontend
        if errorlevel 1 goto failed
        echo [START] Frontend server window opened.
    )
)

call :port_available 8082
if errorlevel 2 goto port_check_failed
if errorlevel 1 (
    echo [SKIP] Port 8082 is already in use. The existing process was left running.
) else (
    if defined HRIS_CHECK_ONLY (
        echo [CHECK] Reverb port 8082 is available.
    ) else (
        start "HRIS Reverb - 8082" /D "%HRIS_BACKEND%" "%ComSpec%" /D /K call "%~f0" --reverb
        if errorlevel 1 goto failed
        echo [START] Reverb server window opened.
    )
)

call :queue_available
if errorlevel 2 goto queue_check_failed
if errorlevel 1 (
    echo [SKIP] This project's notification queue worker is already running.
) else (
    if defined HRIS_CHECK_ONLY (
        echo [CHECK] Notification queue worker is ready to start.
    ) else (
        start "HRIS Notification Queue" /D "%HRIS_BACKEND%" "%ComSpec%" /D /K call "%~f0" --queue
        if errorlevel 1 goto failed
        echo [START] Notification queue worker window opened.
    )
)

call "%HRIS_PHP%" "%HRIS_BACKEND%\artisan" telegram:commands --configured --no-interaction >nul 2>&1
if errorlevel 1 (
    echo [SKIP] Telegram is not configured or its configuration could not be loaded.
) else (
    call :telegram_available
    if errorlevel 2 goto telegram_check_failed
    if errorlevel 1 (
        echo [SKIP] This project's Telegram command listener is already running.
    ) else (
        if defined HRIS_CHECK_ONLY (
            echo [CHECK] Telegram command listener is ready to start.
        ) else (
            start "HRIS Telegram Bot" /D "%HRIS_BACKEND%" "%ComSpec%" /D /K call "%~f0" --telegram
            if errorlevel 1 goto failed
            echo [START] Telegram command listener window opened.
        )
    )
)

if defined HRIS_CHECK_ONLY echo [PASS] Launcher paths and runtimes are ready. No servers were started.
goto finish

:run_backend
cd /D "%HRIS_BACKEND%"
call "%HRIS_PHP%" artisan serve --host=0.0.0.0 --port=8002 --tries=1 --no-reload
exit /B %errorlevel%

:run_frontend
cd /D "%HRIS_FRONTEND%"
"%HRIS_NODE%" "%HRIS_FRONTEND%\node_modules\vite\bin\vite.js" --host 0.0.0.0 --port 5002 --strictPort
exit /B %errorlevel%

:run_reverb
cd /D "%HRIS_BACKEND%"
call "%HRIS_PHP%" "%HRIS_BACKEND%\artisan" reverb:start --host=0.0.0.0 --port=8082 --no-interaction
exit /B %errorlevel%

:run_queue
cd /D "%HRIS_BACKEND%"
call "%HRIS_PHP%" "%HRIS_BACKEND%\artisan" queue:work database --queue=notifications --sleep=1 --tries=4 --timeout=60 --no-interaction
exit /B %errorlevel%

:run_telegram
cd /D "%HRIS_BACKEND%"
call "%HRIS_PHP%" "%HRIS_BACKEND%\artisan" telegram:poll --no-interaction
exit /B %errorlevel%

:port_available
"%HRIS_NODE%" -e "const socket = require('node:net').connect({host: '127.0.0.1', port: Number(process.argv[1])}); socket.setTimeout(2000); socket.once('connect', () => { socket.destroy(); process.exit(1); }); socket.once('error', error => process.exit(error.code === 'ECONNREFUSED' ? 0 : 2)); socket.once('timeout', () => { socket.destroy(); process.exit(2); });" %~1
exit /B %errorlevel%

:queue_available
powershell.exe -NoProfile -NonInteractive -Command "try { $artisan = Join-Path $env:HRIS_BACKEND 'artisan'; $workers = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -and $_.CommandLine.IndexOf($artisan, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and $_.CommandLine -match '\squeue:work\s' -and $_.CommandLine -match '--queue=notifications(?:\s|$)' }); if ($workers.Count -gt 0) { exit 1 }; exit 0 } catch { exit 2 }"
exit /B %errorlevel%

:telegram_available
powershell.exe -NoProfile -NonInteractive -Command "try { $artisan = Join-Path $env:HRIS_BACKEND 'artisan'; $listeners = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -and $_.CommandLine.IndexOf($artisan, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and $_.CommandLine -match '\stelegram:poll(?:\s|$)' }); if ($listeners.Count -gt 0) { exit 1 }; exit 0 } catch { exit 2 }"
exit /B %errorlevel%

:port_check_failed
echo [ERROR] Could not check the local server port. No process was stopped.
goto failed

:queue_check_failed
echo [ERROR] Could not check the project's notification queue worker.
goto failed

:telegram_check_failed
echo [ERROR] Could not check the project's Telegram command listener.
goto failed

:failed
set "HRIS_RESULT=1"

:finish
if defined HRIS_NO_PAUSE exit /B %HRIS_RESULT%
echo.
echo Close a server window or press Ctrl+C there to stop that server.
echo Press any key to close this launcher.
pause >nul
exit /B %HRIS_RESULT%
