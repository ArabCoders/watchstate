<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Throwable;

class EmbyServer extends JellyfinServer
{
    public const NAME = 'EmbyBackend';

    protected const WEBHOOK_ALLOWED_TYPES = [
        'Movie',
        'Episode',
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

    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        string|int|null $uuid = null,
        array $persist = [],
        array $options = []
    ): ServerInterface {
        $options['emby'] = true;

        return parent::setUp($name, $url, $token, $userId, $uuid, $persist, $options);
    }

    public static function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $logger = null;

        try {
            $logger = $opts[LoggerInterface::class] ?? Container::get(LoggerInterface::class);

            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'Emby Server/')) {
                return $request;
            }

            $payload = (string)ag($request->getParsedBody() ?? [], 'data', null);

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'SERVER_ID' => ag($json, 'Server.Id', ''),
                'SERVER_NAME' => ag($json, 'Server.Name', ''),
                'SERVER_VERSION' => afterLast($userAgent, '/'),
                'USER_ID' => ag($json, 'User.Id', ''),
                'USER_NAME' => ag($json, 'User.Name', ''),
                'WH_EVENT' => ag($json, 'Event', 'not_set'),
                'WH_TYPE' => ag($json, 'Item.Type', 'not_set'),
            ];

            foreach ($attributes as $key => $val) {
                $request = $request->withAttribute($key, $val);
            }
        } catch (Throwable $e) {
            $logger?->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        if (null === ($json = $request->getParsedBody())) {
            throw new HttpException(sprintf('%s: No payload.', afterLast(__CLASS__, '\\')), 400);
        }

        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', self::NAME, $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', self::NAME, $event), 200);
        }

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
            $isWatched = 1;
        } elseif ('item.markunplayed' === $event) {
            $isWatched = 0;
        } else {
            $isWatched = (int)(bool)ag($json, ['Item.Played', 'Item.PlayedToCompletion'], false);
        }

        $providersId = ag($json, 'Item.ProviderIds', []);

        $row = [
            'type' => $type,
            'updated' => time(),
            'watched' => $isWatched,
            'via' => $this->name,
            'title' => ag($json, ['Item.Name', 'Item.OriginalTitle'], '??'),
            'year' => ag($json, 'Item.ProductionYear', 0000),
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids($providersId),
            'extra' => [
                'date' => makeDate(
                    ag($json, ['Item.PremiereDate', 'Item.ProductionYear', 'Item.DateCreated'], 'now')
                )->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
        ];

        if (StateInterface::TYPE_EPISODE === $type) {
            $row['title'] = ag($json, 'Item.SeriesName', '??');
            $row['season'] = ag($json, 'Item.ParentIndexNumber', 0);
            $row['episode'] = ag($json, 'Item.IndexNumber', 0);

            if (null !== ($epTitle = ag($json, ['Name', 'OriginalTitle'], null))) {
                $row['extra']['title'] = $epTitle;
            }

            if (null !== ag($json, 'Item.SeriesId')) {
                $row['parent'] = $this->getEpisodeParent(ag($json, 'Item.SeriesId'), '');
            }
        }

        $entity = Container::get(StateInterface::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported External ids.', self::NAME);

            if (empty($providersId)) {
                $message .= ' Most likely unmatched movie/episode or show.';
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => !empty($providersId) ? $providersId : 'None']));

            throw new HttpException($message, 400);
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.Id');
        }

        $savePayload = true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug');

        if (false === $isTainted && $savePayload) {
            saveWebhookPayload($this->name . '.' . $event, $request, $entity);
        }

        return $entity;
    }

    protected function getEpisodeParent(mixed $id, string $cacheName): array
    {
        if (array_key_exists($id, $this->cacheShow)) {
            return $this->cacheShow[$id];
        }

        try {
            $response = $this->http->request(
                'GET',
                (string)$this->url->withPath(
                    sprintf('/Users/%s/items/' . $id, $this->user)
                ),
                $this->getHeaders()
            );

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if (null === ($itemType = ag($json, 'Type')) || 'Series' !== $itemType) {
                return [];
            }

            $providersId = (array)ag($json, 'ProviderIds', []);

            if (!$this->hasSupportedIds($providersId)) {
                $this->cacheShow[$id] = [];
                return $this->cacheShow[$id];
            }

            $this->cacheShow[$id] = Guid::fromArray($this->getGuids($providersId))->getAll();

            return $this->cacheShow[$id];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode \'%s\' JSON response. %s', $this->name, $cacheName, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Failed to handle \'%s\' response. %s', $this->name, $cacheName, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]
            );
            return [];
        }
    }
}
