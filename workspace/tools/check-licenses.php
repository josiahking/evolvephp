<?php

const APPROVED_LICENSES = array(
    'MIT',
    'BSD-3-Clause',
    'Apache-2.0',
);

$lockPath = __DIR__ . '/../composer.lock';

foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--lock=') === 0) {
        $lockPath = substr($argument, strlen('--lock='));
    }
}

$contents = file_get_contents($lockPath);

if ($contents === false) {
    fwrite(STDERR, 'Unable to read Composer lockfile: ' . $lockPath . PHP_EOL);
    exit(1);
}

$lock = json_decode($contents, true);

if (!is_array($lock) || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, 'Malformed Composer lockfile JSON: ' . $lockPath . PHP_EOL);
    exit(1);
}

$failures = array();

foreach (array('packages', 'packages-dev') as $section) {
    if (!isset($lock[$section]) || !is_array($lock[$section])) {
        continue;
    }

    foreach ($lock[$section] as $package) {
        $name = isset($package['name']) ? $package['name'] : '<unknown>';
        $version = isset($package['version']) ? $package['version'] : '<unknown>';
        $licenses = isset($package['license']) ? $package['license'] : null;

        if (!is_array($licenses) || $licenses === array()) {
            $failures[] = $name . ' ' . $version . ' has missing licence data in ' . $section . '.';
            continue;
        }

        foreach ($licenses as $license) {
            if (!is_string($license) || !in_array($license, APPROVED_LICENSES, true)) {
                $reportedLicense = is_string($license) ? $license : '<invalid>';
                $failures[] = $name . ' ' . $version . ' has unapproved licence ' . $reportedLicense . ' in ' . $section . '.';
            }
        }
    }
}

if ($failures !== array()) {
    fwrite(STDERR, 'Composer locked dependency licence policy failed:' . PHP_EOL);

    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }

    fwrite(STDERR, 'Approved licence identifiers: ' . implode(', ', APPROVED_LICENSES) . PHP_EOL);
    exit(1);
}

echo 'Composer locked dependency licence policy passed for packages and packages-dev.' . PHP_EOL;
exit(0);
