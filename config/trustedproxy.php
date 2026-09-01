<?php

// Trusted proxies for apps served behind a reverse proxy (ngrok, a load
// balancer). Read here — NOT in bootstrap/app.php — because config files are
// loaded after the .env file, while bootstrap/app.php is evaluated before it
// and env() there always returns empty.
//
// Value: comma-separated proxy IPs/CIDRs, or '*' to trust the calling IP.
// 127.0.0.1 = the local ngrok agent (`ngrok http 8000`) forwarding requests
// to this app; it is what lets Laravel see the https://<tunnel>.ngrok-free.dev
// scheme/host instead of generating http:// asset URLs that https browsers
// block as mixed content (the "broken frontend over ngrok" failure).
// Empty/null in direct local dev. Trusting '*' on a public network lets
// clients spoof X-Forwarded-For past the login rate limiter.

return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
