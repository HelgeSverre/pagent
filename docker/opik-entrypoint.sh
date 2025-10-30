#!/bin/bash
set -e

echo "Waiting for ClickHouse to be fully ready..."
max_attempts=30
attempt=0

# Wait for ClickHouse to be fully responsive
while [ $attempt -lt $max_attempts ]; do
    if curl -sf http://opik-clickhouse:8123/ > /dev/null 2>&1; then
        echo "ClickHouse is responsive!"
        # Add extra sleep to ensure it's fully initialized
        sleep 5
        break
    fi
    attempt=$((attempt+1))
    echo "Waiting for ClickHouse... attempt $attempt/$max_attempts"
    sleep 2
done

if [ $attempt -eq $max_attempts ]; then
    echo "ClickHouse failed to become ready after $max_attempts attempts"
    exit 1
fi

echo "Running database migrations..."
./run_db_migrations.sh

echo "Starting Opik backend..."
./entrypoint.sh
