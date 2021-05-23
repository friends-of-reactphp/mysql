#!/bin/sh

CONTAINER="mysql"
USERNAME="test"
PASSWORD="test"
while ! docker exec $CONTAINER mysql --user=$USERNAME --password=$PASSWORD -e "SELECT 1" >/dev/null 2>&1; do
    sleep 1
done
