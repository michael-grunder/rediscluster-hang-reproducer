#!/bin/bash

# Note: We set REDISCLI_AUTH in the container so don't have to pass wuth

set -eo pipefail

docker exec -it redis-node-1 redis-cli --cluster-yes --cluster create \
    172.28.0.2:6379 172.28.0.3:6379 172.28.0.4:6379 \
    172.28.0.5:6379 172.28.0.6:6379 172.28.0.7:6379 \
    --cluster-replicas 1

docker exec -it phpredis-client php /root/seed-keys.php
