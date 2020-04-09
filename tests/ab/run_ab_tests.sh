set -x
cd tests/ab

SKIP_DEFLATE=
if [ "$TRAVIS" = "true" ]; then
if [ $(phpenv version-name) = "hhvm" -o $(phpenv version-name) = "5.4" -o $(phpenv version-name) = "5.5" -o $(phpenv version-name) = "5.6" ]; then
    echo "Skipping deflate autobahn tests for $(phpenv version-name)"
    SKIP_DEFLATE=_skip_deflate
fi
fi

docker run --rm \
    -d \
    -v ${PWD}:/config \
    -v ${PWD}/reports:/reports \
    -p 9001:9001 \
    --name fuzzingserver \
    crossbario/autobahn-testsuite wstest -m fuzzingserver -s /config/fuzzingserver$SKIP_DEFLATE.json
sleep 5
if [ "$TRAVIS" != "true" ]; then
    echo "Running tests vs Autobahn test client"
    ###docker run -it --rm --name abpytest crossbario/autobahn-testsuite wstest --mode testeeclient -w ws://host.docker.internal:9001
fi
php -d memory_limit=256M clientRunner.php

docker ps -a

docker logs fuzzingserver

docker stop fuzzingserver



sleep 2

php -d memory_limit=256M startServer.php &
sleep 3


docker run --rm \
    -it \
    -v ${PWD}:/config \
    -v ${PWD}/reports:/reports \
    --name fuzzingclient \
    crossbario/autobahn-testsuite wstest -m fuzzingclient -s /config/fuzzingclient$SKIP_DEFLATE.json
sleep 1

# send the shutdown command to the PHP echo server
wget -O - -q http://127.0.0.1:9001/shutdown


