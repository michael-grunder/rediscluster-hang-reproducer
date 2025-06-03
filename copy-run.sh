#!/bin/bash

docker compose \
    cp phpredis-client:/root/.local/share/rr/php83-0 \
    rr/php-$(date +%s)
