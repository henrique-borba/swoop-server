<?php

namespace Swoop\Workers;

use parallel\Runtime;

final class ThreadedWorker extends BaseWorker
{
    public function init(): void
    {
        parent::initProcess();
    }
}
