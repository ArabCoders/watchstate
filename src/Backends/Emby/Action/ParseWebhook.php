<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Common\Context;
use App\Backends\Emby\EmbyActionTrait;
use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class ParseWebhook
{
    use CommonTrait, JellyfinActionTrait, EmbyActionTrait;

    protected const WEBHOOK_ALLOWED_TYPES = [
        EmbyClient::TYPE_MOVIE,
        EmbyClient::TYPE_EPISODE,
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'item.markplayed',
        'item.markunplayed',
        'playback.scrobble',
        'playback.pause',
        'playback.start',
        'playback.stop',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'playback.pause',
        'playback.start',
        'playback.stop',
    ];

    /**
     * Parse Plex Webhook payload.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param iRequest $request
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->parse($context, $guid, $request, $opts),
        );
    }

    private function parse(Context $context, iGuid $guid, iRequest $request): Response
    {
        if (null === ($json = $request->getParsedBody())) {
            return new Response(status: false, extra: [
                'http_code' => 400,
                'message' => $context->clientName . ': No payload.'
            ]);
        }

        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');
        $id = ag($json, 'Item.Id');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => sprintf('%s: Webhook content type [%s] is not supported.', $context->backendName, $type)
            ]);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => sprintf('%s: Webhook event type [%s] is not supported.', $context->backendName, $event)
            ]);
        }

        if (null === $id) {
            return new Response(status: false, extra: [
                'http_code' => 400,
                'message' => $context->backendName . ': No item id was found in body.'
            ]);
        }

        try {
            $lastPlayedAt = null;

            if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
                $lastPlayedAt = time();
                $isPlayed = 1;
            } elseif ('item.markunplayed' === $event) {
                $isPlayed = 0;
            } else {
                $isPlayed = (int)(bool)ag($json, ['Item.Played', 'Item.PlayedToCompletion'], false);
            }
            $fields = [
                iFace::COLUMN_EXTRA => [
                    $context->backendName => [
                        iFace::COLUMN_EXTRA_EVENT => $event,
                        iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                    ],
                ],
            ];

            if (null !== $lastPlayedAt && 1 === $isPlayed) {
                $fields += [
                    iFace::COLUMN_UPDATED => $lastPlayedAt,
                    iFace::COLUMN_WATCHED => $isPlayed,
                    iFace::COLUMN_META_DATA => [
                        $context->backendName => [
                            iFace::COLUMN_WATCHED => (string)(int)(bool)$isPlayed,
                            iFace::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ];
            }

            $obj = $this->getItemDetails(context: $context, id: $id);

            $guids = $guid->get(ag($json, 'Item.ProviderIds', []), context: [
                'item' => [
                    'id' => ag($obj, 'Id'),
                    'type' => ag($obj, 'Type'),
                    'title' => match (ag($obj, 'Type')) {
                        EmbyClient::TYPE_MOVIE => sprintf(
                            '%s (%s)',
                            ag($obj, ['Name', 'OriginalTitle'], '??'),
                            ag($obj, 'ProductionYear', '0000')
                        ),
                        EmbyClient::TYPE_EPISODE => trim(
                            sprintf(
                                '%s - (%sx%s)',
                                ag($obj, 'SeriesName', '??'),
                                str_pad((string)ag($obj, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                                str_pad((string)ag($obj, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                            )
                        ),
                    },
                    'year' => ag($obj, 'ProductionYear'),
                ],
            ]);

            if (count($guids) >= 1) {
                $guids += Guid::makeVirtualGuid($context->backendName, (string)$id);
                $fields[iFace::COLUMN_GUIDS] = $guids;
                $fields[iFace::COLUMN_META_DATA][$context->backendName][iFace::COLUMN_GUIDS] = $fields[iFace::COLUMN_GUIDS];
            }

            $entity = $this->createEntity(
                context: $context,
                guid:    $guid,
                item:    $obj,
                opts:    ['override' => $fields],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                return new Response(
                    status: false,
                    error:  new Error(
                                message: 'Ignoring [%(backend)] [%(title)] webhook event. No valid/supported external ids.',
                                context: [
                                             'backend' => $context->backendName,
                                             'title' => $entity->getName(),
                                             'context' => [
                                                 'attributes' => $request->getAttributes(),
                                                 'parsed' => $entity->getAll(),
                                                 'payload' => $request->getParsedBody(),
                                             ],
                                         ],
                                level:   Levels::ERROR
                            ),
                    extra:  [
                                'http_code' => 200,
                                'message' => $context->backendName . ': Import ignored. No valid/supported external ids.'
                            ],
                );
            }

            return new Response(status: true, response: $entity);
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error:  new Error(
                            message: 'Unhandled exception was thrown during [%(backend)] webhook event parsing.',
                            context: [
                                         'backend' => $context->backendName,
                                         'exception' => [
                                             'file' => $e->getFile(),
                                             'line' => $e->getLine(),
                                             'kind' => get_class($e),
                                             'message' => $e->getMessage(),
                                         ],
                                         'context' => [
                                             'attributes' => $request->getAttributes(),
                                             'payload' => $request->getParsedBody(),
                                         ],
                                     ],
                            level:   Levels::ERROR
                        ),
                extra:  [
                            'http_code' => 200,
                            'message' => $context->backendName . ': Failed to handle payload. Check logs.'
                        ],
            );
        }
    }
}