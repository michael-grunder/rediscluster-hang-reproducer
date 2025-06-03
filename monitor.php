<?php

const REDIS_PORT = 6379;
const REDIS_TIMEOUT = 0.5;
const SLEEP_TIME = 5;

while (true) {
    $statuses = [];

    for ($i = 1; $i < 6; $i++) {
        try {
            $r = new Redis();
            $r->connect("redis-node-$i", REDIS_PORT, REDIS_TIMEOUT);
            $r->auth(getenv('REDISCLI_AUTH'));
            if ($r->ping()) {
                $statuses[] = "OK";
            } else {
                $statuses[] = "ERR";
            }
        } catch (Throwable $e) {
            $statuses[] = "";
        }
    }

    echo "Cluster statuses: [" . implode(', ', $statuses) . ']' . PHP_EOL;
    sleep(SLEEP_TIME);
}
