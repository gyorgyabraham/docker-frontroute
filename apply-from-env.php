#!/usr/bin/env php
<?php

function env($name, $default = null)
{
    $ret = getenv($name);
    if ($ret === false) {
        $ret = $default;
    }
    return $ret;
}

function getUrlFromEnv($prefix, $name)
{
    // check for preferred port definitions
    foreach (array(80, 8080, 8000) as $port) {
        $best = env($prefix . '_PORT_' . $port . '_TCP');
        if ($best !== null) {
            return $best;
        }
    }

    // use first available port definition
    $url = env($prefix . '_PORT');

    // still no URL found
    if ($url === null && $prefix !== 'SCRIPT') {
        echo 'Skip ' . $prefix . ' because it has no port defined' . PHP_EOL;
    }

    return $url;
}

// collected server instances
$servers = array();

foreach ($_SERVER as $key => $value) {
    if (substr($key, -5) === '_NAME') {
        $prefix = substr($key, 0, -5);

        $name = $value;
        $pos = strrpos($name, '/');
        if ($pos !== false) {
            $name = substr($name, $pos + 1);
        }

        $url = getUrlFromEnv($prefix, $name);
        if ($url === null) {
            continue;
        }

        $url = str_replace('tcp://', 'http://', $url);
        $servers[$name] = $url;

        echo '/' . $name . ' => ' . $url . PHP_EOL;
    }
}

if (!$servers) {
    echo 'No servers found' . PHP_EOL;
    exit(1);
}

$config = '# automatically generated by /apply-from-env.php
server {
    listen 80;
';

foreach ($servers as $name => $url) {
    $config .= '

    # proxy for ' . $name . '
    location /' . $name . '/ {
        proxy_pass ' . $url . ';

        # rewrite redirect / location headers to match this subdir
        proxy_redirect default;
        proxy_redirect / $scheme://$http_host/' . $name . '/;

        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $remote_addr;
    }

    # requests without trailing slash will be forwarded to include slash
    location = /' . $name . ' {
        return 301 $scheme://$http_host$uri/$is_args$args;
    }';
}

$config .= '
}
';

file_put_contents('/etc/nginx/sites-enabled/default', $config);

