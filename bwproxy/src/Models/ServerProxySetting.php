<?php

namespace BWProject\BWProxy\Models;

use Illuminate\Database\Eloquent\Model;

class ServerProxySetting extends Model
{
    protected $table = 'bwproxy_server_settings';

    protected $fillable = [
        'server_uuid',
        'node_id',
        'mode',
        'socks5_host',
        'socks5_port',
        'socks5_username',
        'socks5_password',
        'awg_interface',
    ];

    protected function casts(): array
    {
        return [
            'socks5_port' => 'integer',
            'socks5_password' => 'encrypted',
        ];
    }
}
