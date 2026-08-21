# WinSCP FTP/SFTP Deployment Script - Hlasovaci Portal
# Automaticka priprava ciste produkcni verze a nahrani na FTP/SFTP server

param(
    [string]$ConfigFile = "deploy-config.json"
)

# Kontrola existence konfiguracniho souboru
$configPath = Join-Path $PSScriptRoot $ConfigFile
if (-not (Test-Path $configPath)) {
    Write-Host "CHYBA: Konfiguracni soubor '$ConfigFile' nebyl nalezen!" -ForegroundColor Red
    Write-Host "Zkopirujte 'deploy-config.example.json' na '$ConfigFile' a vyplnte FTP udaje." -ForegroundColor Yellow
    exit 1
}

# Nacteni konfigurace
try {
    $config = Get-Content $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
}
catch {
    Write-Host "CHYBA: Nelze nacist konfiguraci: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

# Detekce a kontrola WinSCP knihovny
$winscpCandidates = @(
    "C:\Program Files (x86)\WinSCP\WinSCPnet.dll",
    "C:\Program Files\WinSCP\WinSCPnet.dll",
    "$env:LOCALAPPDATA\Programs\WinSCP\WinSCPnet.dll"
)
$winscpDllPath = $winscpCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $winscpDllPath) {
    Write-Host "CHYBA: WinSCP nebyl nalezen v systemu!" -ForegroundColor Red
    Write-Host "Hledano v:" -ForegroundColor Gray
    $winscpCandidates | ForEach-Object { Write-Host "  - $_" -ForegroundColor Gray }
    Write-Host "Prosim nainstalujte WinSCP z https://winscp.net/eng/download.php" -ForegroundColor Yellow
    exit 1
}

Write-Host "======================================================" -ForegroundColor Cyan
Write-Host "  Skautsky Hlasovaci Portal - FTP Deployment" -ForegroundColor Cyan
Write-Host "======================================================" -ForegroundColor Cyan
Write-Host "Server:        $($config.ftpHost)" -ForegroundColor Gray
Write-Host "Uzivatel:      $($config.ftpUsername)" -ForegroundColor Gray
Write-Host "Protokol:      $($config.protocol)" -ForegroundColor Gray
Write-Host "Cilova slozka: $($config.remotePath)" -ForegroundColor Gray
Write-Host ""

# Nacteni WinSCP .NET assembly
Add-Type -Path $winscpDllPath

# Priprava docasneho staging adresare pro prenos
$stagingPath = Join-Path $PSScriptRoot "_deploy_temp"

try {
    Write-Host "[1/3] Priprava produkcnich souboru k nahrani..." -ForegroundColor Yellow

    # Vycisteni pripadneho stareho staging adresare
    if (Test-Path $stagingPath) {
        Remove-Item $stagingPath -Recurse -Force
    }
    New-Item -ItemType Directory -Path $stagingPath | Out-Null

    # 1. Slozky aplikace
    $foldersToCopy = @("app", "vendor", "www")
    foreach ($folder in $foldersToCopy) {
        $src = Join-Path $PSScriptRoot $folder
        if (Test-Path $src) {
            $dest = Join-Path $stagingPath $folder
            Copy-Item -Path $src -Destination $dest -Recurse -Force
        }
    }

    # 2. Slozka config (kopirujeme vse krome lokalniho dev local.neon)
    $configSrc = Join-Path $PSScriptRoot "config"
    $configDest = Join-Path $stagingPath "config"
    New-Item -ItemType Directory -Path $configDest | Out-Null

    Get-ChildItem -Path $configSrc -File | ForEach-Object {
        # Lokalni vyvojovy local.neon nepenosime (obsahuje lokalni hesla / isTest: true)
        if ($_.Name -ne "local.neon" -and $_.Name -ne "local.production.neon" -and -not $_.Name.EndsWith(".backup")) {
            Copy-Item -Path $_.FullName -Destination $configDest -Force
        }
    }

    # Pokud existuje config/local.production.neon, prejmenujeme jej v cili na local.neon
    $prodNeon = Join-Path $configSrc "local.production.neon"
    if (Test-Path $prodNeon) {
        Write-Host "  -> Pouzivam konfiguracni soubor 'config/local.production.neon' jako 'local.neon'..." -ForegroundColor Green
        Copy-Item -Path $prodNeon -Destination (Join-Path $configDest "local.neon") -Force
    }

    # 3. Vytvoreni prazdnych slozek temp a log
    New-Item -ItemType Directory -Path (Join-Path $stagingPath "temp") | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $stagingPath "log") | Out-Null

    # 4. Korenove soubory (composer.json, .htaccess pokud existuje)
    $rootFiles = @("composer.json", "composer.lock", ".htaccess")
    foreach ($rf in $rootFiles) {
        $rPath = Join-Path $PSScriptRoot $rf
        if (Test-Path $rPath) {
            Copy-Item -Path $rPath -Destination $stagingPath -Force
        }
    }

    Write-Host "      Soubory byly pripraveny do docasneho adresare." -ForegroundColor Green
    Write-Host ""

    # [2/3] Pripojeni a synchronizace pres WinSCP
    Write-Host "[2/3] Pripojuji se k serveru..." -ForegroundColor Cyan

    $sessionOptions = New-Object WinSCP.SessionOptions -Property @{
        Protocol   = if ($config.protocol -eq "SFTP") { [WinSCP.Protocol]::Sftp } else { [WinSCP.Protocol]::Ftp }
        HostName   = $config.ftpHost
        UserName   = $config.ftpUsername
        Password   = $config.ftpPassword
        PortNumber = if ($config.ftpPort) { [int]$config.ftpPort } else { 0 }
    }

    # Pro FTP zapneme pasivni rezim pokud je vychozi
    if ($config.protocol -ne "SFTP") {
        $sessionOptions.FtpMode = [WinSCP.FtpMode]::Passive
    }

    $session = New-Object WinSCP.Session

    try {
        $session.Open($sessionOptions)
        Write-Host "      Pripojeno uspesne!" -ForegroundColor Green
        Write-Host ""

        # Nastaveni parametru prenosu
        $transferOptions = New-Object WinSCP.TransferOptions
        $transferOptions.TransferMode = [WinSCP.TransferMode]::Binary
        
        # Ignorovat soubory, ktere by se nikdy nemely nahrat
        $transferOptions.FileMask = @"
| .git/; .idea/; .vscode/; *.md; .gitignore; .gitattributes; deploy.ps1; deploy.bat; deploy-config.json; deploy-config.example.json; *.zip; *.log; Thumbs.db; .DS_Store; temp/cache/*
"@

        Write-Host "Synchronizuji soubory (nahravaji se pouze nove a zmenene soubory)..." -ForegroundColor Cyan
        Write-Host ""

        # Synchronizace z docasneho adresare na FTP
        $synchronizationResult = $session.SynchronizeDirectories(
            [WinSCP.SynchronizationMode]::Remote,
            $stagingPath,
            $config.remotePath,
            $False,  # Nemazat soubory na serveru, ktere nejsou lokalne (napre. serverovy local.neon a logy)
            $False,  # Zrcadleni vypnuto pro bezpecnost databaze/logu
            [WinSCP.SynchronizationCriteria]::Time,
            $transferOptions
        )

        # Overeni vysledku
        $synchronizationResult.Check()

        # Statistiky
        Write-Host ""
        Write-Host "=== Vysledek nahravani ===" -ForegroundColor Green
        Write-Host "Nahrano / aktualizovano souboru: $($synchronizationResult.Uploads.Count)" -ForegroundColor Green

        if ($synchronizationResult.Uploads.Count -gt 0) {
            Write-Host ""
            Write-Host "Zmenene soubory:" -ForegroundColor Gray
            foreach ($upload in $synchronizationResult.Uploads) {
                $fileName = Split-Path $upload.FileName -Leaf
                Write-Host "  + $fileName" -ForegroundColor Gray
            }
        }
        else {
            Write-Host "Vsechny soubory na serveru jsou jiz aktualni." -ForegroundColor Yellow
        }

        if ($synchronizationResult.Failures.Count -gt 0) {
            Write-Host ""
            Write-Host "Chyby pri nahravani ($($synchronizationResult.Failures.Count)):" -ForegroundColor Red
            foreach ($failure in $synchronizationResult.Failures) {
                Write-Host "  ! $($failure.Message)" -ForegroundColor Red
            }
        }

        Write-Host ""
        Write-Host "Deployment dokoncen!" -ForegroundColor Green
    }
    finally {
        if ($session) {
            $session.Dispose()
        }
    }
}
catch {
    Write-Host ""
    Write-Host "CHYBA pri deploymentu: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
finally {
    # [3/3] Uklid docasneho staging adresare
    Write-Host ""
    Write-Host "[3/3] Uklid docasneho adresare..." -ForegroundColor Yellow
    if (Test-Path $stagingPath) {
        Remove-Item $stagingPath -Recurse -Force -ErrorAction SilentlyContinue
    }
    Write-Host "      Docasne soubory smazany." -ForegroundColor Green
}

Write-Host ""
Write-Host "Hotovo!" -ForegroundColor Cyan
