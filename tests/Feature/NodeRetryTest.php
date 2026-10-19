<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Api\Node\NodeApi;

function retryNode(): NodeApi
{
    config()->set('evm.http.retry_sleep_ms', 0);

    return new NodeApi(baseURL: 'https://rpc.test', chainId: 137);
}

it('repeats a request whose DNS lookup timed out', function () {
    Http::fakeSequence('rpc.test*')
        ->pushFailedConnection('cURL error 28: Resolving timed out after 10000 milliseconds')
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x10']);

    expect(retryNode()->rpc('eth_blockNumber'))->toBe('0x10');
    Http::assertSentCount(2); // the failed attempt, then the repeat
});

it('repeats when the host could not be reached at all', function () {
    Http::fakeSequence('rpc.test*')
        ->pushFailedConnection('cURL error 6: Could not resolve host: rpc.test')
        ->pushFailedConnection('cURL error 7: Failed to connect to rpc.test port 443')
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x20']);

    expect(retryNode()->rpc('eth_blockNumber'))->toBe('0x20');
});

it('gives up after the configured retries', function () {
    config()->set('evm.http.retries', 1);

    Http::fakeSequence('rpc.test*')
        ->pushFailedConnection('cURL error 28: Resolving timed out after 20000 milliseconds')
        ->pushFailedConnection('cURL error 28: Resolving timed out after 20000 milliseconds')
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x30']);

    expect(fn () => retryNode()->rpc('eth_blockNumber'))->toThrow(ConnectionException::class);
});

it('does not repeat a request that may have reached the node', function () {
    Http::fakeSequence('rpc.test*')
        ->pushFailedConnection('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received')
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x40']);

    // e.g. eth_sendRawTransaction: sent, answer lost — repeating is not this layer's call
    expect(fn () => retryNode()->rpc('eth_sendRawTransaction', ['0xdead']))->toThrow(ConnectionException::class);
});

it('tells failures before connecting from the others', function (string $message, bool $before) {
    expect(NodeApi::failedBeforeConnecting(new ConnectionException($message)))->toBe($before);
})->with([
    ['cURL error 28: Resolving timed out after 10000 milliseconds', true],
    ['cURL error 6: Could not resolve host: polygon-mainnet.g.alchemy.com', true],
    ['cURL error 7: Failed to connect to x port 443 after 3 ms: Couldn\'t connect to server', true],
    ['cURL error 28: Connection timed out after 20001 milliseconds', true],
    ['cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received', false],
    ['cURL error 56: Recv failure: Connection reset by peer', false],
]);
