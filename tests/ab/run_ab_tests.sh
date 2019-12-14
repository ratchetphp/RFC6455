cd tests/ab

wstest -m fuzzingserver -s fuzzingserver.json &
sleep 5
php clientRunner.php

sleep 2

php startServer.php &
sleep 3
wstest -m fuzzingclient -s fuzzingclient.json
sleep 1
kill $(ps aux | grep 'php startServer.php' | awk '{print $2}' | head -n 1)
