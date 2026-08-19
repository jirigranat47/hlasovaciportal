<?php

declare(strict_types=1);

/**
 * Skript pro sestavení produkčního instalačního balíčku (release.zip)
 *
 * Spuštění:
 *   php bin/build-release.php
 */

$rootDir = dirname(__DIR__);
$zipFile = $rootDir . '/hlasovaci-portal-release.zip';

echo "======================================================\n";
echo "  ⚜ Skautský Hlasovací Portál - Produkční Build\n";
echo "======================================================\n\n";

// 1. Spuštění optimalizace Composeru
echo "[1/4] Optimalizuji Composer balíčky (--no-dev --optimize-autoloader)...\n";
exec('composer install --no-dev --optimize-autoloader --no-interaction', $output, $exitCode);
if ($exitCode !== 0) {
    echo "Upozornění: Příkaz composer nebyl úspěšný nebo nebyl spuštěn přes CLI.\n";
} else {
    echo "      Composer optimalizován.\n";
}

// 2. Vyčištění temp cache
echo "[2/4] Promazávám dočasnou mezipaměť (temp/cache)...\n";
$tempCacheDir = $rootDir . '/temp/cache';
if (is_dir($tempCacheDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    echo "      Cache promazána.\n";
}

// 3. Vytvoření ZIP archivu
echo "[3/4] Vytvářím produkční balíček 'hlasovaci-portal-release.zip'...\n";
if (file_exists($zipFile)) {
    @unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("CHYBA: Nelze vytvořit soubor {$zipFile}\n");
}

$includeDirs = ['app', 'config', 'sql', 'vendor', 'www'];
$includeFiles = ['composer.json', 'CRON.md', 'DEPLOYMENT.md', 'README.md'];

// Přidání adresářů
foreach ($includeDirs as $dir) {
    $path = $rootDir . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $filePath = $item->getRealPath();
        $relativePath = substr($filePath, strlen($rootDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        // Ignorujeme lokální konfiguraci a dočasné soubory
        if ($relativePath === 'config/local.neon' || str_contains($relativePath, '/.git')) {
            continue;
        }

        if ($item->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
        }
    }
}

// Přidání kořenových souborů
foreach ($includeFiles as $file) {
    $path = $rootDir . '/' . $file;
    if (file_exists($path)) {
        $zip->addFile($path, $file);
    }
}

// Vytvoření prázdných adresářů temp a log
$zip->addEmptyDir('temp');
$zip->addEmptyDir('log');

$zip->close();

$sizeMb = round(filesize($zipFile) / (1024 * 1024), 2);
echo "      Hotovo! Velikost archivu: {$sizeMb} MB\n\n";

echo "[4/4] Produkční balíček je připraven:\n";
echo "      -> {$zipFile}\n\n";
echo "Postup nasazení naleznete v souboru DEPLOYMENT.md.\n";
