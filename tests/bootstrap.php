<?php

require_once __DIR__.'/../vendor/autoload.php';

// Dotenv reads $_SERVER before $_ENV; synchronize PHPUnit's forced settings.
$_SERVER = array_replace($_SERVER, $_ENV);
