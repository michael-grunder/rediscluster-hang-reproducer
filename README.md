# rediscluster-hang-reproducer

A repository to try and reproduce a bug where `RedisCluster` hangs on reconnect if one of the nodes becomes physically unaavailable.

## Usage

Start the Redis cluster and client container:

```bash
docker compose up -d --remove-orphans
```

Once the containers are running, you can attempt to start Reading keys with `ARedisCluster`.

```bash
$ docker compose exec phpredis bash
# Now you want to start a recording debugging session that reads keys
$ rr record /root/read-keys.php
```

At this point in yet another shell you can stop any of the Redis nodes with docker compose down

```bash
# Or redis-node-2, or redis-node-3, etc
docker compose down redis-node-1
```

If you are able to get `RedisCluster` to hang when it tries to reconnect you can pull down the recording session like so

```bash
docker compose cp phpredis-client:/root/.local/share/rr/php-0 ./php-hang-reproducer
```

This will copy everything including the PHP binary and all shared libraries, which means  the run can be reproduced locally.
