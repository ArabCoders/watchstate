<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Options;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GetLibrary
{
    use CommonTrait;
    use JellyfinActionTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Get Library content.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param string|int $id
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, iGuid $guid, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->action($context, $id, $opts));
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ExceptionInterface
     * @throws \JsonMachine\Exception\InvalidArgumentException
     */
    private function action(Context $context, string|int $id, array $opts = []): Response
    {
        $libraries = $this->getBackendLibraries($context);

        if (null === ($section = ag($libraries, $id))) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'No Library with id [%(id)] found in [%(backend)] response.',
                    context: [
                        'id' => $id,
                        'backend' => $context->backendName,
                        'response' => [
                            'body' => $libraries
                        ],
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        unset($libraries);

        $logContext = [
            'library' => [
                'id' => $id,
                'type' => ag($section, 'CollectionType', 'unknown'),
                'title' => ag($section, 'Name', '??'),
            ],
        ];

        if (true !== in_array(
                ag($logContext, 'library.type'),
                [JellyfinClient::COLLECTION_TYPE_MOVIES, JellyfinClient::COLLECTION_TYPE_SHOWS]
            )) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'The Requested [%(backend)] Library [%(library.id): %(library.title)] returned with unsupported type [%(library.type)].',
                    context: [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/', $context->backendUser))->withQuery(
            http_build_query(
                [
                    'parentId' => $id,
                    'enableUserData' => 'false',
                    'enableImages' => 'false',
                    'excludeLocationTypes' => 'Virtual',
                    'include' => implode(',', [JellyfinClient::TYPE_SHOW, JellyfinClient::TYPE_MOVIE]),
                    'fields' => implode(',', JellyfinClient::EXTRA_FIELDS)
                ]
            )
        );

        $logContext['library']['url'] = (string)$url;

        $this->logger->debug('Requesting [%(backend)] library [%(library.title)] content.', [
            'backend' => $context->backendName,
            ...$logContext,
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [%(backend)] library [%(library.title)] returned with unexpected [%(status_code)] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                        ...$logContext,
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $it = Items::fromIterable(
            iterable: httpClientChunks($this->http->stream($response)),
            options: [
                'pointer' => '/Items',
                'decoder' => new ErrorWrappingDecoder(
                    new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                )
            ]
        );

        $list = [];

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                $this->logger->warning(
                    'Failed to decode one item of [%(backend)] library [%(library.title)] content.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'error' => [
                            'message' => $entity->getErrorMessage(),
                            'body' => $entity->getMalformedJson(),
                        ],
                    ]
                );
                continue;
            }

            $url = $context->backendUrl->withPath(
                sprintf('/Users/%s/items/%s', $context->backendUser, ag($entity, 'Id'))
            );

            $logContext['item'] = [
                'id' => ag($entity, 'Id'),
                'title' => ag($entity, ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'], '??'),
                'year' => ag($entity, 'ProductionYear', '0000'),
                'type' => ag($entity, 'Type'),
                'url' => (string)$url,
            ];

            $list[] = $this->process($context, $entity, $logContext, $opts);
        }

        return new Response(status: true, response: $list);
    }

    private function process(Context $context, array $item, array $log = [], array $opts = []): array
    {
        $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/%s', $context->backendUser, ag($item, 'Id')));
        $possibleTitlesList = ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'];

        $data = [
            'backend' => $context->backendName,
            ...$log,
        ];

        if ($context->trace) {
            $data['trace'] = $item;
        }

        $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))].', $data);

        $metadata = [
            'id' => ag($item, 'Id'),
            'type' => ucfirst(ag($item, 'Type', 'unknown')),
            'library' => ag($log, 'library.title'),
            'url' => (string)$url,
            'title' => ag($item, $possibleTitlesList, '??'),
            'year' => ag($item, 'ProductionYear'),
            'guids' => [],
            'match' => [
                'titles' => [],
                'paths' => [],
            ],
        ];

        foreach ($possibleTitlesList as $title) {
            if (null === ($title = ag($item, $title))) {
                continue;
            }

            $isASCII = mb_detect_encoding($title, 'ASCII', true);
            $title = trim($isASCII ? strtolower($title) : mb_strtolower($title));

            if (true === in_array($title, $metadata['match']['titles'])) {
                continue;
            }

            $metadata['match']['titles'][] = $title;
        }

        if (null !== ($path = ag($item, 'Path'))) {
            $metadata['match']['paths'][] = [
                'full' => $path,
                'short' => basename($path),
            ];

            if (ag($item, 'Type') === 'Movie') {
                if (false === str_starts_with(basename($path), basename(dirname($path)))) {
                    $metadata['match']['paths'][] = [
                        'full' => $path,
                        'short' => basename($path),
                    ];
                }
            }
        }

        if (null !== ($providerIds = ag($item, 'ProviderIds'))) {
            foreach ($providerIds as $key => $val) {
                $metadata['guids'][] = $key . '://' . $val;
            }
        }

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $metadata['raw'] = $item;
        }

        return $metadata;
    }
}
