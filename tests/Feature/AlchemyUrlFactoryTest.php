<?php

use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;

it('builds the Alchemy URL of a known chain, Robinhood Chain included', function () {
    expect(AlchemyUrlFactory::make(137, 'KEY'))->toBe('https://polygon-mainnet.g.alchemy.com/v2/KEY')
        ->and(AlchemyUrlFactory::make(4663, 'KEY'))->toBe('https://robinhood-mainnet.g.alchemy.com/v2/KEY')
        ->and(AlchemyUrlFactory::supports(4663))->toBeTrue()
        ->and(AlchemyUrlFactory::make(999999, 'KEY'))->toBeNull();
});

it('takes extra networks from the config without a release', function () {
    config()->set('evm.alchemy.networks', [999999 => 'newchain-mainnet', 'bad' => 'x', 5 => '']);

    expect(AlchemyUrlFactory::supports(999999))->toBeTrue()
        ->and(AlchemyUrlFactory::make(999999, 'KEY'))->toBe('https://newchain-mainnet.g.alchemy.com/v2/KEY')
        ->and(AlchemyUrlFactory::supports(5))->toBeFalse()
        // built-in ones are still there
        ->and(AlchemyUrlFactory::make(1, 'KEY'))->toBe('https://eth-mainnet.g.alchemy.com/v2/KEY');
});
