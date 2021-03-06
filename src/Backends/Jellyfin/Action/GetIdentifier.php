<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GetIdentifier
{
    use CommonTrait;

    private string $action = 'unique identifier';

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * Get Backend unique identifier.
     *
     * @param Context $context
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $opts) {
                $url = $context->backendUrl->withPath('/system/Info');

                $this->logger->debug('Requesting [%(client): %(backend)] unique identifier.', [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'url' => $url
                ]);

                $response = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
                );

                if (200 !== $response->getStatusCode()) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: 'Request for [%(backend)] %(action) returned with unexpected [%(status_code)] status code.',
                            context: [
                                'action' => $this->action,
                                'client' => $context->clientName,
                                'backend' => $context->backendName,
                                'status_code' => $response->getStatusCode(),
                            ],
                            level: Levels::WARNING
                        )
                    );
                }

                $item = json_decode(
                    json: $response->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                if (true === $context->trace) {
                    $this->logger->debug('Processing [%(client): %(backend)] %(action) payload.', [
                        'action' => $this->action,
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'trace' => $item,
                    ]);
                }

                return new Response(
                    status: true,
                    response: ag($item, 'Id', null)
                );
            },
            action: $this->action,
        );
    }
}
