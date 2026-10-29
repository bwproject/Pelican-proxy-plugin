<?php

return [
    // JSON object keyed by numeric Pelican node ID, e.g. {"1":"long-random-token"}.
    // Keep this only in the panel .env; never commit node tokens.
    'node_tokens' => json_decode((string) env('BWPROXY_NODE_TOKENS', '{}'), true) ?: [],
    'agent_timeout' => (int) env('BWPROXY_AGENT_TIMEOUT', 10),
];
