cd tests/ab

wstest -m fuzzingserver -s fuzzingserver.travis.json &
sleep 5
php clientRunner.php

sleep 2

php startServer.php 25 &
sleep 3
wstest -m fuzzingclient -s fuzzingclient.travis.json
sleep 2