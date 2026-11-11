<?php

namespace App\Runner;

use RuntimeException;

/**
 * Someone pressed Cancel while the run was working.
 */
class RunCancelled extends RuntimeException
{
    //
}
