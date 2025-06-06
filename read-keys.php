<?php

function toFailoverConstant(string $value): ?int {
    return match (strtoupper($value)) {
        'NONE' => RedisCluster::FAILOVER_NONE,
        'ERROR' => RedisCluster::FAILOVER_ERROR,
        'DISTRIBUTE' => RedisCluster::FAILOVER_DISTRIBUTE,
        'DISTRIBUTE_SLAVES' => RedisCluster::FAILOVER_DISTRIBUTE_SLAVES,
        default => NULL,
    };
}

function toFailoverString(int $value): string {
    return match ($value) {
        RedisCluster::FAILOVER_NONE => 'none',
        RedisCluster::FAILOVER_ERROR => 'error',
        RedisCluster::FAILOVER_DISTRIBUTE => 'distribute',
        RedisCluster::FAILOVER_DISTRIBUTE_SLAVES => 'distribute-slaves',
        default => throw new Exception("Unknown failover const"),
    };
}

$opts = getopt('', [
    'keys:',             // Number of keys
    'timeout::',         // connection timeout (seconds)
    'read-timeout::',    // read timeout (seconds)
    'max-retries::',     // PhpRedis OPT_MAX_RETRIES
    'failover:',         // Failover mode (default: FAILOVER_DISTRIBUTE)
    'sleep::',           // usleep between ops (seconds, float)
    'tick::',            // how often (seconds) to print status
    'commands::',        // comma‑list of commands (GET,SET,PING)
    'multi',             // enable occasional MULTI/EXEC blocks
]);

const SEEDS = [
    '172.28.0.2:6379',
    '172.28.0.3:6379',
    '172.28.0.4:6379',
    '172.28.0.5:6379',
    '172.28.0.6:6379',
    '172.28.0.7:6379',
];

const DEFAULT_KEYS         = 100;
const DEFAULT_TIMEOUT      = 0.1;
const DEFAULT_READ_TIMEOUT = 0.1;
const DEFAULT_MAX_RETRIES  = 1;
const DEFAULT_SLEEP        = 0.1;
const DEFAULT_TICK         = 1.0;
const DEFAULT_COMMANDS     = ['GET', 'SET', 'MGET', 'PING'];
const DEFAULT_FAILOVER     = 'distribute';

$keys        = (int)($opts['keys'] ?? DEFAULT_KEYS);
$timeout     = (float)($opts['timeout']      ?? DEFAULT_TIMEOUT);
$readTimeout = (float)($opts['read-timeout'] ?? DEFAULT_READ_TIMEOUT);
$maxRetries  = (int)  ($opts['max-retries']  ?? DEFAULT_MAX_RETRIES);
$sleep       = (float)($opts['sleep']        ?? DEFAULT_SLEEP);
$tick        = (float)($opts['tick']         ?? DEFAULT_TICK);
$failover    = $opt['failover'] ?? DEFAULT_FAILOVER;
$sleep_usec  = (int)($sleep * 1_000_000);
$useMulti    = isset($opt['multi']);

$commands = $opt['commands'] ?? DEFAULT_COMMANDS;
if ( ! is_array($commands) ) {
    $commands = explode(',', $commands);
}

$failover_opt = toFailoverConstant($failover);
if ($failover_opt === null) {
    fprintf(STDERR, "Invalid failover mode '%s'. Valid values are: NONE, ERROR, DISTRIBUTE, DISTRIBUTE_SLAVES.\n", $failover);
    exit(1);
}

$auth = getenv('REDISCLI_AUTH') ?: null;
if (!$auth) {
    fwrite(STDERR, "WARNING: Connecting without authentication.\n");
}

function connectCluster(array $seeds, float $timeout, float $rto, int $retries,
                        ?string $auth, int $failover): RedisCluster
{
    printf("[%.3f] Connecting (timeout: %.2f/%.2f, retries: %d, failover: %s)...\n",
           microtime(true), $timeout, $rto, $retries, toFailoverString($failover));

    $t0 = microtime(true);
    $c  = new RedisCluster(null, $seeds, $timeout, $rto, false, $auth);

    if ($c->setOption(Redis::OPT_MAX_RETRIES, $retries) == false) {
        fprintf(STDERR, "Error: Can't set max-retries?\n");
        exit(1);
    }

    if ($c->setOption(RedisCluster::OPT_SLAVE_FAILOVER, $failover) == false) {
        fprintf(STDERR, "Error: Can't set failov er mode?\n");
        exit(1);
    }

    printf("[%.3f] Connected (%.4fs)\n", microtime(true), microtime(true) - $t0);

    return $c;
}

function getKeys(string $type, int $keys, int $n): array {
    $result = [];

    assert($n > 0);

    for ($i = 0; $i < $n; $i++) {
        $result[] = sprintf("%s:%d", $type, rand() % $keys);
    }

    return $result;
}

function getKey(string $type, int $keys): string {
    return sprintf("%s:%d", $type, rand() % $keys);
}

function runCommand(RedisCluster $rc, string $cmd, int $keys): mixed {
    switch ($cmd) {
        case 'GET':
        case 'PING':
        case 'ECHO':
            return $rc->{$cmd}(getKey('string', $keys));
        case 'SET':
            return $rc->set(getKey('string', $keys), "value:" . time());
        case 'MGET':
            return $rc->mget(getKeys('string', $keys, rand(1, 5)));
        default:
            throw new InvalidArgumentException("Unknown command $cmd");
    }
}

$cluster   = null;
$counter   = 0;
$inMulti   = false;
$lastPrint = microtime(true);
$counter = 0;

while (++$counter) {
    try {
        $cluster ??= connectCluster(SEEDS, $timeout, $readTimeout, $maxRetries,
                                    $auth, $failover_opt);

        $cmd = $commands[array_rand($commands)];

        if ($useMulti) {
            if (!$inMulti && mt_rand(0, 99) < 2) {
                $cluster->multi();
                $inMulti = true;
            } elseif ($inMulti && mt_rand(0, 99) < 10) {
                $cluster->exec();
                $inMulti = false;
            }
        }

        $t0  = microtime(true);
        $res = runCommand($cluster, $cmd, $keys);
        $t1  = microtime(true);

        if (($t1 - $lastPrint) >= $tick && !$inMulti) {
            printf("[%.3f #%d %s] %s -> %s (%.4fs)\n",
                   $inMulti ? 'M' : 'A', $t1, $counter, $cmd,
                   var_export($res, true), $t1 - $t0);
            $lastPrint = $t1;
        }

    } catch (Throwable $e) {
        fprintf(STDERR, "[%.3f] Exception: %s\n", microtime(true),
                $e->getMessage());
        $cluster = null;
        $inMulti = false;
    }

    if ($sleep_usec > 0)
        usleep($sleep_usec);
}
