<?php
declare(strict_types=1);

// Router para o servidor embutido do PHP (desenvolvimento sem Docker):
//   php -S localhost:8080 -t public router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/public' . $path)) {
    return false; // ficheiro estático
}
require __DIR__ . '/public/index.php';
