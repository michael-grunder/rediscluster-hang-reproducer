#!/bin/bash
set -euo pipefail

trap 'echo "❌ Script exited early (status=$?). Check logs above."' EXIT

echo "⏳ Waiting for Redis nodes to respond..."
until redis-cli -h redis-node-1 ping >/dev/null 2>&1; do
  sleep 0.1
done

# Check if cluster is already up
if redis-cli -h redis-node-1 cluster info 2>/dev/null | grep -q 'cluster_state:ok'; then
  echo "✅ Cluster already initialized. Skipping creation."
else
  echo "🚀 Creating Redis Cluster..."
  redis-cli --cluster-yes --cluster create \
    redis-node-1:6379 redis-node-2:6379 redis-node-3:6379 \
    redis-node-4:6379 redis-node-5:6379 redis-node-6:6379 \
    --cluster-replicas 1
fi

echo "⏳ Waiting for cluster_state:ok..."
until redis-cli -h redis-node-1 cluster info 2>/dev/null | grep -q 'cluster_state:ok'; do
  sleep 0.2
done

echo "🌱 Seeding keys..."
for i in {1..100}; do
  redis-cli -c -h redis-node-1 SET "string:$i" "value:$i" >/dev/null
done

echo "📊 Starting live cluster stats monitor..."

php /root/monitor.php
