<?php

namespace Omnilab\OpenTargets;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Open Targets Platform: targets, diseases and drugs, their associations
 * with scores, a search - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     associations: 10                 # associations (and drugs) an entity holds as properties, the best first
 *     throttle: 0                      # seconds between two calls: Open Targets publishes no rate
 *     base_uri: 'https://api.platform.opentargets.org/api/v4/'
 */
final class OpenTargetsSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'opentargets',
            'omnilab.required_options' => [],
            'associations' => 10,
            'throttle' => 0.0,
            'base_uri' => OpenTargetsSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new OpenTargetsSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('associations'),
            (string) $c->get('base_uri', OpenTargetsSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
