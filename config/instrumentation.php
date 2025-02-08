<?php

declare(strict_types=1);

if (!extension_loaded('ddtrace')) {
    return;
}

\DDTrace\trace_method(
    \Keboola\DockerBundle\Docker\Image::class,
    'pullImage',
    function (\DDTrace\SpanData $span, $args, $ret, $exception) {
    }
);

\DDTrace\trace_method(
    \Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader::class,
    'loadInputData',
    function (\DDTrace\SpanData $span, $args, $ret, $exception) {
    }
);

\DDTrace\trace_method(
    \Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader::class,
    'storeOutput',
    function (\DDTrace\SpanData $span, $args, $ret, $exception) {
    }
);
