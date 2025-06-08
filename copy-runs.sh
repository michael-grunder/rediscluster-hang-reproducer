#!/bin/bash

mkdir -p rr

ID=0
NOW=$(date +%s)

while true; do
    SRC="phpredis:/root/.local/share/rr/php-${ID}"
    DST="rr/php-${NOW}.${ID}"

    if ! docker compose cp "$SRC" "$DST"; then
        echo "Failed to find php-${ID}, exiting."
        break
    fi

    ID=$((ID + 1))
done

