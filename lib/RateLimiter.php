<?php

class RateLimiter
{
    private $delayUntil = array();

    public function wait($key, $delay = 1.0)
    {
        if (isset($this->delayUntil[$key])) {
            $delta = $this->delayUntil[$key] - microtime(true);
            if ($delta > 0) {
                printf("RateLimiter: sleeping=%.4fs key=%s\n", $delta, $key);
                usleep((int)($delta * 1e6));
            }
        }
        $this->delayUntil[$key] = microtime(true) + $delay;
    }
}
