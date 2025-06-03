<?php

define('SEEDS', [
    '172.28.0.2:6379', '172.28.0.3:6379', '172.28.0.4:6379',
    '172.28.0.5:6379', '172.28.0.6:6379', '172.28.0.7:6379',
]);

function waitForCluster(): RedisCluster {
    $auth = getenv('REDISCLI_AUTH') ?? NULL;

    printf("Waiting for cluster");
    do {
        try {
            return new RedisCluster(NULL, SEEDS, auth: $auth);
        } catch (RedisClusterException $e) {
            printf("Exception: %s\n", $e->getMessage());
            usleep(100000);
        }
    } while (true);
}

$opt = getopt('', ['keys:']);
$keys = (int)($opt['keys'] ?? 100);

$auth = getenv('REDISCLI_AUTH') ?: null;
if (!$auth) {
    fwrite(STDERR, "WARNING: REDISCLI_AUTH not set. Connecting without authentication.\n");
}

$rc = waitForCluster();
for ($i = 0; $i < $keys; $i++) {
    $rc->set("string:$i", "value:$i");
}

echo "\nKeys set! \\o/\n";

exit(0);
