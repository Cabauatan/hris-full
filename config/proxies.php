<?php

$trustedProxies = [];

foreach (explode(',', (string) env('TRUSTED_PROXIES', '')) as $proxy) {
    $proxy = trim($proxy);

    if ($proxy === '') {
        continue;
    }

    $parts = explode('/', $proxy);
    $address = $parts[0];
    $maximumPrefix = str_contains($address, ':') ? 128 : 32;

    if (count($parts) > 2
        || filter_var($address, FILTER_VALIDATE_IP) === false
        || (isset($parts[1]) && (! ctype_digit($parts[1])
            || (int) $parts[1] < 1
            || (int) $parts[1] > $maximumPrefix))) {
        $trustedProxies = [];

        break;
    }

    $trustedProxies[] = $proxy;
}

return [
    'trusted' => array_values(array_unique($trustedProxies)),
];
