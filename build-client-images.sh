#!/bin/bash

branches=(6.0.2 6.1.0 develop)

[[ "$1" == "-p" ]] && PUSH=true

for branch in "${branches[@]}"; do
    echo "Building Docker image for branch: $branch"
    docker build \
        -t mgrunder/phpredis-cluster-client:$branch \
        --build-arg PHPREDIS_BRANCH=$branch .

    if [[ "$PUSH" == true ]]; then
        docker push mgrunder/phpredis-cluster-client:$branch
            echo "Docker image for branch $branch built and pushed successfully."
    fi
done
