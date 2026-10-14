<?php

declare(strict_types=1);

$loader = require __DIR__ . '/vendor/autoload.php';

$loader->add('Drupal\Tests', __DIR__ . '/web/core/tests');
$loader->add('Drupal\KernelTests', __DIR__ . '/web/core/tests');
$loader->add('Drupal\FunctionalTests', __DIR__ . '/web/core/tests');
$loader->add('Drupal\FunctionalJavascriptTests', __DIR__ . '/web/core/tests');
$loader->add('Drupal\TestTools', __DIR__ . '/web/core/tests');
$loader->add('Drupal\BuildTests', __DIR__ . '/web/core/tests');
$loader->add('Drupal\TestSite', __DIR__ . '/web/core/tests');

$extension_roots = [
    __DIR__ . '/web/modules',
    __DIR__ . '/web/profiles',
    __DIR__ . '/web/themes',
];

$dirs = [];
foreach ($extension_roots as $root) {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::FOLLOW_SYMLINKS));
        foreach ($iterator as $dir) {
            if (str_contains($dir->getPathname(), '.info.yml')) {
                $ext = substr($dir->getFilename(), 0, -9);
                $dirs[$ext] = $dir->getPathInfo()->getRealPath();
            }
        }
    }
}

foreach ($dirs as $extension => $dir) {
    if (is_dir($dir . '/src')) {
        $loader->addPsr4('Drupal\\' . $extension . '\\', [$dir . '/src']);
    }
    if (is_dir($dir . '/tests/src')) {
        $loader->addPsr4('Drupal\\Tests\\' . $extension . '\\', [$dir . '/tests/src']);
    }
}

setlocale(LC_ALL, 'C.UTF-8', 'C');
mb_internal_encoding('utf-8');
mb_language('uni');
date_default_timezone_set('Australia/Sydney');
