<?php

declare(strict_types=1);

namespace App\Webhook\Connector;

use App\Container\LoggerAwareTrait;
use App\Entity\Api\NowPlaying\NowPlaying;
use App\Entity\StationWebhook;
use App\Utilities;
use GuzzleHttp\Client;
use InvalidArgumentException;
use PhpIP\IP;
use RuntimeException;

abstract class AbstractConnector implements ConnectorInterface
{
    use LoggerAwareTrait;

    public function __construct(
        protected Client $httpClient
    ) {
    }

    /**
     * @inheritDoc
     */
    public function shouldDispatch(StationWebhook $webhook, array $triggers = []): bool
    {
        if (!$this->webhookShouldTrigger($webhook, $triggers)) {
            $this->logger->debug(
                sprintf(
                    'Webhook "%s" will not run for triggers: %s; skipping...',
                    $webhook->getName(),
                    implode(', ', $triggers)
                )
            );
            return false;
        }

        $rateLimitTime = $this->getRateLimitTime($webhook);
        if (null !== $rateLimitTime && !$webhook->checkRateLimit($rateLimitTime)) {
            $this->logger->notice(
                sprintf(
                    'Webhook "%s" has run less than %d seconds ago; skipping...',
                    $webhook->getName(),
                    $rateLimitTime
                )
            );
            return false;
        }

        return true;
    }

    /**
     * @param \App\Entity\StationWebhook $webhook
     * @param array<string> $triggers
     *
     */
    protected function webhookShouldTrigger(StationWebhook $webhook, array $triggers = []): bool
    {
        if (!$webhook->hasTriggers()) {
            return true;
        }

        foreach ($triggers as $trigger) {
            if ($webhook->hasTrigger($trigger)) {
                return true;
            }
        }

        return false;
    }

    protected function getRateLimitTime(StationWebhook $webhook): ?int
    {
        return 10;
    }

    /**
     * Replace variables in the format {{ blah }} with the flattened contents of the NowPlaying API array.
     *
     * @param array $raw_vars
     * @param \App\Entity\Api\NowPlaying\NowPlaying $np
     *
     * @return array
     */
    public function replaceVariables(array $raw_vars, NowPlaying $np): array
    {
        $values = Utilities\Arrays::flattenArray($np);
        $vars = [];

        foreach ($raw_vars as $var_key => $var_value) {
            // Replaces {{ var.name }} with the flattened $values['var.name']
            $vars[$var_key] = preg_replace_callback(
                "/\{\{(\s*)([a-zA-Z\d\-_.]+)(\s*)}}/",
                static function ($matches) use ($values) {
                    $inner_value = strtolower(trim($matches[2]));
                    return $values[$inner_value] ?? '';
                },
                $var_value
            );
        }

        return $vars;
    }

    /**
     * Determine if a passed URL is valid and return it if so, or return null otherwise.
     *
     * @param string|null $url_string
     */
    protected function getValidUrl(?string $url_string = null): ?string
    {
        $uri = Utilities\Urls::tryParseUserUrl(
            $url_string,
            'Webhook'
        );

        if (null === $uri) {
            return null;
        }

        // Check for IP addresses that shouldn't be used in user-provided URLs.
        try {
            $ip = IP::create($uri->getHost());
            if ($ip->isReserved()) {
                throw new RuntimeException('URL references an IANA reserved block.');
            }
        } catch (InvalidArgumentException) {
            // Noop, URL is not an IP
        }

        return (string)$uri;
    }

    protected function incompleteConfigException(StationWebhook $webhook): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf(
                'Webhook "%s" (type "%s") is missing necessary configuration. Skipping...',
                $webhook->getName(),
                $webhook->getType()->value
            ),
        );
    }
}
