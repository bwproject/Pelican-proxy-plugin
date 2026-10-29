<?php

return [
    'mode' => env('BWPROXY_MODE', 'off'),
    'socks5_host' => env('BWPROXY_SOCKS5_HOST', ''),
    'socks5_port' => (int) env('BWPROXY_SOCKS5_PORT', 1080),
    'socks5_username' => env('BWPROXY_SOCKS5_USERNAME', ''),
    'socks5_password' => env('BWPROXY_SOCKS5_PASSWORD', ''),
    'awg_interface' => env('BWPROXY_AWG_INTERFACE', 'awg0'),
];
