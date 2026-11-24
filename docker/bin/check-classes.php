<?php
/**
 * Loads every class the project declares through the autoloader and fails if any of them is missing or raises a
 * diagnostic on the way in.
 *
 * Fat-Free installs an error handler that turns any reported diagnostic - including E_WARNING and E_DEPRECATED -
 * into $f3->error(500, ...), and HALT defaults to true, so a diagnostic PHP used to ignore is a fatal here. A
 * class that only breaks when a dependency upgrade changes a signature is therefore worth catching in CI rather
 * than in a worker at 3am. Run from the image: php docker/bin/check-classes.php
 */

$root = dirname(__DIR__, 2);
chdir($root);

$diagnostics = [];

set_error_handler(function (int $no, string $message, string $file, int $line) use (&$diagnostics) {
    $diagnostics[] = sprintf('%s in %s:%d', $message, str_replace(getcwd() . '/', '', $file), $line);
    return true;
});

require $root . '/vendor/autoload.php';

$resolved = 0;
$missing  = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

foreach ($files as $file)
{
    if ($file->getExtension() !== 'php')
        continue;

    $code = file_get_contents($file->getPathname());

    if (!preg_match('/namespace\s+([^;]+);/', $code, $namespace))
        continue;

    if (!preg_match('/^(?:final |abstract )?(?:class|interface|trait|enum)\s+(\w+)/m', $code, $declaration))
        continue;

    $name = trim($namespace[1]) . '\\' . $declaration[1];

    if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name))
        $resolved++;
    else
        $missing[] = $name;
}

restore_error_handler();

printf("%d classes resolved, %d missing, %d diagnostics\n", $resolved, count($missing), count($diagnostics));

foreach ($missing as $name)
    echo "MISSING: $name\n";

foreach (array_unique($diagnostics) as $diagnostic)
    echo "DIAGNOSTIC: $diagnostic\n";

exit($missing || $diagnostics ? 1 : 0);
