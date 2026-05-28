<?php

declare(strict_types=1);

/**
 * Runs `php -l` over every PHP source file in the project.
 * Invoked by `composer lint`.
 */

$root = dirname(__DIR__);
chdir($root);

$files = [];

foreach (['src', 'tests'] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

foreach (['bin/mcp', 'public/index.php'] as $extra) {
    if (file_exists($extra)) {
        $files[] = $extra;
    }
}

$bad = 0;
foreach ($files as $file) {
    $output = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $rc);
    if ($rc !== 0) {
        echo implode("\n", $output), "\n";
        $bad++;
    }
}

if ($bad === 0) {
    echo "Lint OK: " . count($files) . " files\n";
}

exit($bad > 0 ? 1 : 0);
