<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Ausgabepuffer: Der Code ruft http_response_code()/header() auf – ohne Puffer würde
// PHP in der CLI wegen „headers already sent“ warnen, sobald PHPUnit etwas ausgibt.
ob_start();

date_default_timezone_set('Europe/Berlin');
