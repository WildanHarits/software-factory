<?php

namespace App\Data\Providers;

/**
 * A GitHub pull request or GitLab merge request.
 */
readonly class PullRequest
{
    public function __construct(
        public int $number,
        public string $url,
    ) {
        //
    }
}
