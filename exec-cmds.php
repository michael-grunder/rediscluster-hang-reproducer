#!/usr/bin/env php
<?php declare(strict_types=1);

use RedisCluster as RC;


$cliOpts = getopt('', [
    'keys::',          // how many distinct keys in the key-space
    'timeout::',       // connect timeout (s)
    'read-timeout::',  // read timeout (s)
    'max-retries::',   // PhpRedis OPT_MAX_RETRIES
    'failover::',      // NONE|ERROR|DISTRIBUTE|DISTRIBUTE_SLAVES
    'sleep::',         // delay between ops (s float)
    'tick::',          // how often to print status (s)
    'commands::',      // CSV list to restrict commands
    'types::',         // CSV list to restrict key types
    'mode::',          // read|write|all
    'multi',           // enable random MULTI/EXEC
]);

const DEFAULTS = [
    'keys'         => 100,
    'timeout'      => 0.1,
    'read_timeout' => 0.1,
    'max_retries'  => 1,
    'failover'     => 'distribute',
    'sleep'        => 0.01,
    'tick'         => 1.0,
    'commands'     => null,
    'types'        => null,
    'mode'         => 'all',
    'multi'        => false,
];

/* merge CLI → $config */
$config = DEFAULTS;
foreach ($cliOpts as $k => $v) {
    $k            = str_replace('-', '_', $k);
    $config[$k]   = $v !== false ? $v : true;   // flags → bool true
}


function randKey(?string $type, int $max): string
{
    $type ??= ['string', 'hash', 'list', 'set', 'zset']
        [array_rand(['s', 'h', 'l', 't', 'z'])];
    return sprintf('%s:%d', $type, random_int(0, $max - 1));
}

function randKeys(?string $type, int $max, int $n): array
{
    return array_map(fn () => randKey($type, $max), range(1, $n));
}

function randHash(int $n): array
{
    $h = [];
    for ($i = 0; $i < $n; $i++) $h["f$i"] = "v$i";
    return $h;
}

function randZMembers(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = random_int(1, 100);            // score
        $out[] = "m$i";                         // member
    }
    return $out;
}

function toFailConst(string $s): int
{
    return match (strtoupper($s)) {
        'NONE'              => RC::FAILOVER_NONE,
        'ERROR'             => RC::FAILOVER_ERROR,
        'DISTRIBUTE'        => RC::FAILOVER_DISTRIBUTE,
        'DISTRIBUTE_SLAVES' => RC::FAILOVER_DISTRIBUTE_SLAVES,
        default             => RC::FAILOVER_DISTRIBUTE,
    };
}


$CMD_TABLE = [
    /* strings */
    ['name'=>'GET',    'rw'=>'read',  'keytype'=>'string',
     'genArgs'=>fn($k)=>[randKey('string',$k)]],
    ['name'=>'SET',    'rw'=>'write', 'keytype'=>'string',
     'genArgs'=>fn($k)=>[randKey('string',$k), 'value:'.time()]],
    ['name'=>'INCR',   'rw'=>'write', 'keytype'=>'string',
     'genArgs'=>fn($k)=>[randKey('string',$k)]],
    /* generic */
    ['name'=>'DEL',    'rw'=>'write', 'keytype'=>'generic',
     'genArgs'=>fn($k)=>[...randKeys(null,$k,random_int(1,5))]],
    ['name'=>'PING',   'rw'=>'read',  'keytype'=>'generic',
     'genArgs'=>fn($k)=>[randKey(null,$k)]],
    /* multi-key strings */
    ['name'=>'MGET',   'rw'=>'read',  'keytype'=>'string',
     'genArgs'=>fn($k)=>[randKeys('string',$k,random_int(2,5))]],
    /* hashes */
    ['name'=>'HMSET',  'rw'=>'write', 'keytype'=>'hash',
     'genArgs'=>fn($k)=>[randKey('hash',$k), randHash(random_int(1,5))]],
    ['name'=>'HMGET',  'rw'=>'read',  'keytype'=>'hash',
     'genArgs'=>fn($k)=>[randKey('hash',$k),
                         array_keys(randHash(random_int(1,5)))]],
    ['name'=>'HGETALL','rw'=>'read',  'keytype'=>'hash',
     'genArgs'=>fn($k)=>[randKey('hash',$k)]],
    /* lists */
    ['name'=>'LPUSH',  'rw'=>'write', 'keytype'=>'list',
     'genArgs'=>fn($k)=>[randKey('list',$k), 'v'.random_int(1,100)]],
    ['name'=>'LRANGE', 'rw'=>'read',  'keytype'=>'list',
     'genArgs'=>fn($k)=>[randKey('list',$k), 0, 10]],
    /* sorted sets */
    ['name'=>'ZADD',   'rw'=>'write', 'keytype'=>'zset',
     'genArgs'=>fn($k)=>[randKey('zset',$k), ...randZMembers(random_int(1,3))]],
    ['name'=>'ZCARD',  'rw'=>'read',  'keytype'=>'zset',
     'genArgs'=>fn($k)=>[randKey('zset',$k)]],
];

/* apply CLI filters */
$userCmds  = $config['commands']
    ? array_map('strtoupper', explode(',', $config['commands']))
    : null;

$wantTypes = $config['types']
    ? array_map('strtolower', explode(',', $config['types']))
    : null;

$wantMode  = strtolower($config['mode']);

$COMMANDS = array_values(array_filter(
    $CMD_TABLE,
    function ($c) use ($userCmds, $wantTypes, $wantMode) {
        if ($userCmds && !in_array($c['name'], $userCmds, true))       return false;
        if ($wantTypes && !in_array($c['keytype'], $wantTypes, true))  return false;
        if ($wantMode !== 'all' && $c['rw'] !== $wantMode)            return false;
        return true;
}));

if (!$COMMANDS) {
    fwrite(STDERR, "No commands selected after filtering.\n");
    exit(1);
}


const SEEDS = [
    '172.28.0.2:6379', '172.28.0.3:6379', '172.28.0.4:6379',
    '172.28.0.5:6379', '172.28.0.6:6379', '172.28.0.7:6379',
];

function connectCluster(array $cfg): RC
{
    $c = new RC(
        null,
        SEEDS,
        (float) $cfg['timeout'],
        (float) $cfg['read_timeout'],
        false,
        getenv('REDISCLI_AUTH') ?: null,
    );
    $c->setOption(Redis::OPT_MAX_RETRIES, (int) $cfg['max_retries']);
    $c->setOption(RC::OPT_SLAVE_FAILOVER, toFailConst($cfg['failover']));
    return $c;
}

$cluster   = null;
$inMulti   = false;
$lastPrint = microtime(true);
$counter   = 0;
$cmdStats  = [];

while (true) {
    try {
        $cluster ??= connectCluster($config);

        /* random MULTI/EXEC */
        if ($config['multi']) {
            if (!$inMulti && random_int(0, 99) < 2) {
                $cluster->multi();
                $inMulti = true;
            } elseif ($inMulti && random_int(0, 99) < 10) {
                $cluster->exec();
                $inMulti = false;
            }
        }

        ++$counter;
        $cmdSpec = $COMMANDS[array_rand($COMMANDS)];
        $args    = ($cmdSpec['genArgs'])((int) $config['keys']);

        $t0  = microtime(true);
        $res = $cluster->{$cmdSpec['name']}(...$args);
        $t1  = microtime(true);

        /* stats */
        $name               = $cmdSpec['name'];
        $cmdStats[$name]    = ($cmdStats[$name] ?? 0) + 1;

        /* periodic output */
        if (($t1 - $lastPrint) >= $config['tick'] && !$inMulti) {
            printf(
                "[%0.3f #%d %s] %s -> %s (%0.4fs)\n",
                $t1,
                $counter,
                $cmdSpec['rw'][0] === 'w' ? 'W' : 'R',
                $name,
                is_bool($res) ? ($res ? 'TRUE' : 'FALSE')
                              : (is_array($res) ? '[array]' : $res),
                $t1 - $t0,
            );

            /* print sorted command histogram */
            arsort($cmdStats);
            $summary = implode(', ',
                array_map(fn ($n, $c) => "$n $c",
                          array_keys($cmdStats), $cmdStats));
            echo "CMDS: $summary\n";

            $lastPrint = $t1;
        }
    } catch (Throwable $e) {
        fprintf(STDERR, "[%0.3f] Exception: %s\n", microtime(true),
                $e->getMessage());
        $cluster  = null;
        $inMulti  = false;
    }

    if ($config['sleep'] > 0) {
        usleep((int) ($config['sleep'] * 1_000_000));
    }
}
