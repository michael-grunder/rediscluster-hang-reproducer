#!/bin/bash

branches=(6.0.2 6.1.0 develop)

for branch in "${branches[@]}"; do
    echo "Building Docker image for branch: $branch"
    docker build \
        -t mgrunder/phpredis-cluster-client:$branch \
        --build-arg PHPREDIS_BRANCH=$branch .
done
