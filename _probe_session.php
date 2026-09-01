<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Cookie\CookieValuePrefix;

$store = app('session.store');
$store->start();
$token = $store->token();
$cookieName = config('session.cookie');
$prefix = CookieValuePrefix::create($cookieName, app('encrypter')->getKey());
$encrypted = app('encrypter')->encrypt($prefix.$store->getId(), false);

file_put_contents(
    __DIR__.'/_probe_out.txt',
    $encrypted."\n".$token."\n".$cookieName."\n".url('/')."\n".$store->getId()
);

$store->save();
