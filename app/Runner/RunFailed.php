<?php

namespace App\Runner;

use RuntimeException;

/**
 * A run stopped because of a problem. The message is shown to people on the run page.
 */
class RunFailed extends RuntimeException
{
    //
}
