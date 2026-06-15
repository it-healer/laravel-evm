<?php

namespace ItHealer\LaravelEvm\Services\Alchemy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Support\ProxyFormatter;

/**
 * Client for the Alchemy Notify management API (dashboard.alchemy.com/api).
 *
 * Authorized with the Notify "Auth Token" (NOT the RPC API key), passed in the
 * X-Alchemy-Token header. Used to create Address Activity webhooks and keep their
 * watched-address lists in sync with the package's addresses.
 *
 * https://www.alchemy.com/docs/data/webhooks/webhooks-api-endpoints/notify-api-endpoints
 */
class AlchemyNotifyClient
{
    /** Max addresses accepted by update-webhook-addresses per request. */
    public const ADDRESSES_PER_REQUEST = 500;

    protected ?string $proxy;

    public function __construct(
        protected string $authToken,
        protected string $apiUrl = 'https://dashboard.alchemy.com/api',
        ?string $proxy = null,
    ) {
        $this->proxy = ProxyFormatter::format($proxy);
    }

    /**
     * Create an Address Activity webhook.
     *
     * @param  list<string>  $addresses
     * @return array{id: string, signing_key: string, ...}
     */
    public function createWebhook(string $network, string $webhookUrl, array $addresses = []): array
    {
        $data = $this->request()
            ->post('/create-webhook', [
                'network' => $network,
                'webhook_type' => 'ADDRESS_ACTIVITY',
                'webhook_url' => $webhookUrl,
                'addresses' => array_values($addresses),
            ])
            ->throw()
            ->json('data');

        if (!isset($data['id'], $data['signing_key'])) {
            throw new \RuntimeException('Alchemy create-webhook returned an unexpected response.');
        }

        return $data;
    }

    /**
     * All webhooks of the team.
     *
     * @return list<array<string, mixed>>
     */
    public function getWebhooks(): array
    {
        return $this->request()
            ->get('/team-webhooks')
            ->throw()
            ->json('data', []);
    }

    /**
     * Every address currently watched by a webhook (follows the cursor).
     *
     * @return list<string>
     */
    public function getAddresses(string $webhookId): array
    {
        $addresses = [];
        $after = null;

        do {
            $response = $this->request()
                ->get('/webhook-addresses', array_filter([
                    'webhook_id' => $webhookId,
                    'limit' => 100,
                    'after' => $after,
                ]))
                ->throw()
                ->json();

            foreach ($response['data'] ?? [] as $address) {
                $addresses[] = $address;
            }

            $after = $response['pagination']['cursors']['after'] ?? null;
        } while ($after);

        return $addresses;
    }

    /**
     * Add and/or remove watched addresses (batched to the API limit).
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function updateAddresses(string $webhookId, array $add = [], array $remove = []): void
    {
        $add = array_values($add);
        $remove = array_values($remove);

        $batches = max(
            (int)ceil(count($add) / self::ADDRESSES_PER_REQUEST),
            (int)ceil(count($remove) / self::ADDRESSES_PER_REQUEST),
            $add || $remove ? 1 : 0,
        );

        for ($i = 0; $i < $batches; $i++) {
            $offset = $i * self::ADDRESSES_PER_REQUEST;

            $this->request()
                ->patch('/update-webhook-addresses', [
                    'webhook_id' => $webhookId,
                    'addresses_to_add' => array_slice($add, $offset, self::ADDRESSES_PER_REQUEST),
                    'addresses_to_remove' => array_slice($remove, $offset, self::ADDRESSES_PER_REQUEST),
                ])
                ->throw();
        }
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->request()
            ->delete('/delete-webhook', ['webhook_id' => $webhookId])
            ->throw();
    }

    protected function request(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->baseUrl($this->apiUrl)
            ->withHeaders(['X-Alchemy-Token' => $this->authToken])
            ->withOptions(array_filter([
                'timeout' => 30,
                'proxy' => $this->proxy,
            ]));
    }
}
