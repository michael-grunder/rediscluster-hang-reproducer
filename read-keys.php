<?php

$opts = getopt('', [
    'timeout::',
    'read-timeout::',
    'max-retries::',
    'sleep:',
]);

define('SEEDS', [
    '172.28.0.2:6379',
    '172.28.0.3:6379',
    '172.28.0.4:6379',
    '172.28.0.5:6379',
    '172.28.0.6:6379',
    '172.28.0.7:6379',
]);

define('KEYS', 1000);
define('DEFAULT_TIMEOUT', .1);
define('DEFAULT_READ_TIMEOUT', .1);
define('DEFAULT_MAX_RETRIES', 1);
define('DEFAULT_SLEEP', 0.1);

$timeout = (float)($opts['timeout'] ?? DEFAULT_TIMEOUT);
$readTimeout = (float)($opts['read-timeout'] ?? DEFAULT_READ_TIMEOUT);
$maxRetries = (int)($opts['max-retries'] ?? DEFAULT_MAX_RETRIES);
$sleep = (float)($opts['sleep'] ?? DEFAULT_SLEEP);
$sleep_usec = (int)($sleep * 1_000_000);

$auth = getenv('REDISCLI_AUTH') ?: null;
if (!$auth) {
    fwrite(STDERR, "WARNING: REDISCLI_AUTH not set. Connecting without authentication.\n");
}

/**
 * Attempt to connect to the Redis cluster.
 */
function connectCluster(array $seeds, float $timeout, float $readTimeout, int $maxRetries, ?string $auth): RedisCluster
{
    printf("[%.2f] Connecting (timeout: %.2f, readTimeout: %.2f, max_retries: %d)...\n",
           microtime(true), $timeout, $readTimeout, $maxRetries);
    $start = microtime(true);

    $cluster = new RedisCluster(
        null,
        $seeds,
        $timeout,
        $readTimeout,
        false,
        $auth
    );

    $cluster->setOption(Redis::OPT_MAX_RETRIES, $maxRetries);

    $end = microtime(true);
    printf("[%.2f] Cluster connected (%.4f sec)\n", $end, $end - $start);
    return $cluster;
}

$cluster = null;
$n = 0;

while (true) {
    try {
        $cluster ??= connectCluster(SEEDS, $timeout, $readTimeout, $maxRetries,
                                    $auth);

        $key = sprintf("string:%d", $n % KEYS);
        printf("[%.2f %d] GET %s => ", microtime(true), $n, $key);
        $t1 = microtime(true);
        $val = $cluster->get($key);
        $t2 = microtime(true);
        printf("%s (%.4f sec)\n", var_export($val, true), $t2 - $t1);
        $n++;

    } catch (Exception $ex) {
        $t = microtime(true);
        fprintf(STDERR, "[%.2f] Exception: %s\n", $t, $ex->getMessage());
        $cluster = null;
    }

    if ($sleep > 0)
        usleep($sleep_usec);
}
