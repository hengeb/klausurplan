#!/usr/bin/env bash
#
# Führt die Integrationstests gegen eine Wegwerf-Datenbank in Docker aus.
#
#   bin/test-integration.sh                        # alle Integrationstests
#   bin/test-integration.sh --filter ImportUnd     # beliebige PHPUnit-Argumente
#   bin/test-integration.sh --coverage             # zusätzlich Coverage (Unit + Integration)
#   DB_IMAGE=mysql:8.4 bin/test-integration.sh     # gegen MySQL statt MariaDB
#
# Voraussetzung: Docker. Container, Netzwerk und Datenbank werden danach wieder entfernt.
# Es wird nur das Projektverzeichnis eingebunden; eine echte .env wird nicht benötigt.

set -euo pipefail
cd "$(dirname "$0")/.."

DB_IMAGE="${DB_IMAGE:-mariadb:11}"
PHP_VERSION="${PHP_VERSION:-8.5}"
TEST_IMAGE="klausurplan-test:php${PHP_VERSION}"
NAME="klausurplan-test-$$"

COVERAGE=0
ARGS=()
for a in "$@"; do
    if [[ "$a" == "--coverage" ]]; then COVERAGE=1; else ARGS+=("$a"); fi
done

cleanup() {
    docker rm -f "${NAME}-db" >/dev/null 2>&1 || true
    docker network rm "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker build -q --build-arg "PHP_VERSION=${PHP_VERSION}" -t "$TEST_IMAGE" tests/docker >/dev/null

docker network create "$NAME" >/dev/null
docker run -d --name "${NAME}-db" --network "$NAME" \
    -e MARIADB_ROOT_PASSWORD=test -e MYSQL_ROOT_PASSWORD=test "$DB_IMAGE" >/dev/null

echo "Warte auf Datenbank ($DB_IMAGE) …"
for _ in $(seq 1 60); do
    if docker exec "${NAME}-db" sh -c 'mariadb -uroot -ptest -e "select 1" || mysql -uroot -ptest -e "select 1"' >/dev/null 2>&1; then
        READY=1; break
    fi
    sleep 2
done
[[ "${READY:-0}" == "1" ]] || { echo "Datenbank startet nicht" >&2; exit 1; }

if [[ "$COVERAGE" == "1" ]]; then
    PHPUNIT=(php vendor/bin/phpunit --coverage-text --coverage-html coverage "${ARGS[@]}")
else
    PHPUNIT=(php vendor/bin/phpunit --testsuite Integration "${ARGS[@]}")
fi

docker run --rm --network "$NAME" --user "$(id -u):$(id -g)" \
    -e KLAUSURPLAN_TEST_DB_HOST="${NAME}-db" -e KLAUSURPLAN_TEST_DB_USER=root -e KLAUSURPLAN_TEST_DB_PASS=test \
    -v "$PWD:/app" -w /app "$TEST_IMAGE" "${PHPUNIT[@]}"
