<?php

declare(strict_types=1);

namespace Lockally\Symfony;

use GuzzleHttp\Client as GuzzleClient;
use Lockally\SDK\Api\SendApi;
use Lockally\SDK\Configuration as SdkConfiguration;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds a {@see LockallyTransport} from a mailer DSN.
 *
 *   MAILER_DSN=lockally+api://YOUR_API_KEY@default
 *
 * The API key is the DSN user; sandbox vs. live is implicit in the key prefix
 * (`lk_test_` / `lk_live_`), so there is no separate endpoint. A private base URL
 * can be overridden with `?base_url=https://api.internal.example`.
 *
 * Register the factory as a tagged `mailer.transport_factory` service (the Symfony
 * bundle does this automatically once the package is installed).
 */
final class LockallyTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'lockally', $this->getSupportedSchemes());
        }

        $apiKey = $this->getUser($dsn);
        $baseUrl = $dsn->getOption('base_url');

        $cfg = SdkConfiguration::getDefaultConfiguration()->setAccessToken($apiKey);
        if ($baseUrl) {
            $cfg->setHost(rtrim((string) $baseUrl, '/'));
        }

        return new LockallyTransport(new SendApi(new GuzzleClient(), $cfg));
    }

    /** @return string[] */
    protected function getSupportedSchemes(): array
    {
        return ['lockally', 'lockally+api'];
    }
}
