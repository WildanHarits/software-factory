<?php

namespace App\Data\Providers;

/**
 * A GitHub pull request or GitLab merge request.
 */
readonly class PullRequest
{
    public const OPEN = 'open';

    public const MERGED = 'merged';

    public const CLOSED = 'closed';

    public function __construct(
        public int $number,
        public string $url,
        public string $state = self::OPEN,
        /** GitHub's GraphQL id, needed to turn on auto-merge. */
        public ?string $nodeId = null,
    ) {
        //
    }
}
