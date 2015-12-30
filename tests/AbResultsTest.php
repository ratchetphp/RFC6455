<?php

namespace Ratchet\RFC6455\Test;

class AbResultsTest extends \PHPUnit_Framework_TestCase
{
    private function verifyAutobahnResults($fileName)
    {
        $this->assertFileExists($fileName);
        $resultsJson = file_get_contents($fileName);
        $results = json_decode($resultsJson);
        $agentName = array_keys(get_object_vars($results))[0];
        foreach ($results->$agentName as $name => $result) {
            if ($result->behavior === "INFORMATIONAL") {
                continue;
            }
            $this->assertTrue(in_array($result->behavior, ["OK", "NON-STRICT"]), "Autobahn test case " . $name . " in " . $fileName);
        }
    }
    public function testAutobahnClientResults()
    {
        $this->verifyAutobahnResults(__DIR__ . '/ab/reports/clients/index.json');
    }
    public function testAutobahnServerResults()
    {
        $this->verifyAutobahnResults(__DIR__ . '/ab/reports/servers/index.json');
    }
}