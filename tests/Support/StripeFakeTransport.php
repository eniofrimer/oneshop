<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/** SDK contract fixture; an unexpected call fails rather than reaching Stripe. */
class StripeFakeTransport implements ClientInterface
{
    public array $requests = [];

    private array $responses = [];

    public function respond(array|\Throwable $body, int $status = 200): void
    {
        $this->responses[] = [$body, $status];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'headers', 'params', 'maxNetworkRetries');
        if ($this->responses === []) {
            throw new \RuntimeException('Unexpected Stripe test transport call: '.$method.' '.parse_url($absUrl, PHP_URL_PATH));
        }
        [$body, $status] = array_shift($this->responses);
        if ($body instanceof \Throwable) {
            throw $body;
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }

    public function header(array $request, string $name): ?string
    {
        foreach ($request['headers'] as $header) {
            if (str_starts_with(strtolower($header), strtolower($name).':')) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
