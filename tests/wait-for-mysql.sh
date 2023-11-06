#!/bin/sh

CONTAINER="mysql"
USERNAME="test"
PASSWORD="test"
while ! docker exec $CONTAINER mysql --host=127.0.0.1 --port=3306 --user=$USERNAME --password=$PASSWORD -e "SELECT 1" >/dev/null 2>&1; do
    sleep 1
done
