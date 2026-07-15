#!/usr/bin/env bash

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS mailcoach;
    GRANT ALL PRIVILEGES ON \`mailcoach\`.* TO '$MYSQL_USER'@'%';
EOSQL
