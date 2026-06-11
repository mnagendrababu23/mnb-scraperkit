<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Network;

final class ExitPointManager
{
    /** @var array<string,NetworkProfile> */
    private array $profiles = [];

    /** @param array<string,array<string,mixed>> $profiles */
    public function __construct(array $profiles)
    {
        foreach ($profiles as $name => $data) {
            $this->profiles[(string) $name] = NetworkProfile::fromArray((string) $name, (array) $data);
        }
        if ($this->profiles === []) {
            $this->profiles['direct'] = new NetworkProfile('direct');
        }
    }

    public function select(?string $name = null): NetworkProfile
    {
        if ($name && isset($this->profiles[$name])) {
            return $this->profiles[$name];
        }

        foreach ($this->profiles as $profile) {
            if ($profile->enabled) {
                return $profile;
            }
        }

        throw new \RuntimeException('No active network profile available.');
    }
}
