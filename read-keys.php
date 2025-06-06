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

const KEYS                 = 100;
const DEFAULT_TIMEOUT      = 0.1;
const DEFAULT_READ_TIMEOUT = 0.1;
const DEFAULT_MAX_RETRIES  = 1;
const DEFAULT_SLEEP        = 0.1;
const DEFAULT_TICK         = 1.0;
const DEFAULT_COMMANDS     = ['GET', 'SET', 'MGET', 'PING'];
const DEFAULT_FAILOVER     = 'distribute';

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

function runCommand(RedisCluster $rc, string $cmd, int $idx): mixed {
    $key1 = "string:$idx";
    $key2 = "string:" . ($idx + 1) % KEYS;

    switch ($cmd) {
        case 'MGET':
            return $rc->mget([$key1, $key2]);
        case 'GET':
            return $rc->get($key1);
        case 'SET':
            return $rc->set($key1, "value:$idx");
        case 'PING':
            return $rc->ping($key1);
        case 'ECHO':
            return $rc->echo($idx, "hello:$idx");
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

        $idx = $counter % KEYS;
        $t0  = microtime(true);
        $res = runCommand($cluster, $cmd, $idx);
        $t1  = microtime(true);

        if (($t1 - $lastPrint) >= $tick && !$inMulti) {
            printf("[%.3f #%d %s] %s string:%d -> %s (%.4fs)\n",
                   $inMulti ? 'M' : 'A', $t1, $counter, $cmd, $idx,
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
