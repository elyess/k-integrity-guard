#!/bin/sh
# Runs the PHPUnit suite against a real WordPress test install, using Docker only.
# Usage: bin/run-tests.sh [wordpress-version] [php-version]   e.g. bin/run-tests.sh 7.1.4 8.3
set -e
WP_VERSION=${1:-7.1.4}
PHP_VERSION=${2:-8.3}
# wordpress-develop tags major releases as x.y.0 (wordpress.org calls the same download x.y).
case "$WP_VERSION" in *.*.*) DEV_TAG=$WP_VERSION ;; *) DEV_TAG=$WP_VERSION.0 ;; esac
NET=kig-tests
cd "$(dirname "$0")/.."

docker network inspect $NET >/dev/null 2>&1 || docker network create $NET >/dev/null
docker rm -f kig-tests-db >/dev/null 2>&1 || true
docker run -d --name kig-tests-db --network $NET -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress_test mariadb:11 >/dev/null
trap 'docker rm -f kig-tests-db >/dev/null 2>&1' EXIT

docker run --rm --network $NET -v "$PWD":/plugin:ro -e WP_TESTS_DIR=/tmp/wordpress-tests-lib "php:${PHP_VERSION}-cli" sh -c "
set -e
docker-php-ext-install mysqli >/dev/null 2>&1
cd /tmp
curl -sL https://wordpress.org/wordpress-${WP_VERSION}.tar.gz | tar xz
curl -sL https://github.com/WordPress/wordpress-develop/archive/refs/tags/${DEV_TAG}.tar.gz | tar xz --wildcards '*/tests/phpunit/includes' '*/tests/phpunit/data' '*/wp-tests-config-sample.php'
mkdir -p wordpress-tests-lib && mv wordpress-develop-*/tests/phpunit/includes wordpress-develop-*/tests/phpunit/data wordpress-tests-lib/
sed -e \"s:dirname( __FILE__ ) . '/src/':'/tmp/wordpress/':\" -e \"s:__DIR__ . '/src/':'/tmp/wordpress/':\" \
    -e s/youremptytestdbnamehere/wordpress_test/ -e s/yourusernamehere/root/ -e s/yourpasswordhere/root/ -e s/localhost/kig-tests-db/ \
    wordpress-develop-*/wp-tests-config-sample.php > wordpress-tests-lib/wp-tests-config.php
mkdir -p polyfills && curl -sL https://github.com/Yoast/PHPUnit-Polyfills/archive/refs/tags/1.1.4.tar.gz | tar xz -C polyfills --strip-components=1
echo \"define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', '/tmp/polyfills' );\" >> wordpress-tests-lib/wp-tests-config.php
curl -sL -o phpunit.phar https://phar.phpunit.de/phpunit-9.6.phar
for i in \$(seq 1 30); do php -r 'exit(@mysqli_connect(\"kig-tests-db\",\"root\",\"root\") ? 0 : 1);' && break; sleep 2; done
cd /plugin && php /tmp/phpunit.phar -c phpunit.xml.dist --do-not-cache-result --testdox
"
