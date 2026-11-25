<?php

it('fails preflight when required security configuration is missing', function () {
    config([
        'app.key' => '',
        'app.debug' => true,
        'app.business_timezone' => 'Invalid/Timezone',
        'backup.passphrase' => null,
    ]);

    $this->artisan('system:preflight')->assertFailed();
});

it('passes preflight with valid local configuration', function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.debug' => false,
        'app.business_timezone' => 'Asia/Tehran',
        'backup.passphrase' => 'test-passphrase',
    ]);

    $this->artisan('system:preflight')->assertSuccessful();
});
