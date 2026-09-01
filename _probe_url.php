<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force a public base URL (e.g. the ngrok host) so the signed URL matches
// what browser-side Livewire generates through the tunnel.
if (isset($argv[1])) {
    Illuminate\Support\Facades\URL::forceRootUrl($argv[1]);
    Illuminate\Support\Facades\URL::forceScheme('https');
}

echo app('Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl')->forLocal();
