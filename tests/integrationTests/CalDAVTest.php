<?php
declare(strict_types=1);

require_once("../inc/CalDAVClient.php");
require_once("../inc/utils.php");

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

final class CalDAVTest extends TestCase {

    public function setUp(): void {
        $GLOBALS['LOG_HANDLERS'] = array(new StreamHandler('php://stdout', Logger::DEBUG));
    }
    
	#[DataProvider('credentialsProvider')]
    public function testGetCTag($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        $CTag = $client->getCTag();
        $this->assertNotNull($CTag, "CTag: $CTag");
    }
    
	#[DataProvider('credentialsProvider')]
    public function testGetETagAndFetchEvents($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        
        $ETags = $client->getETags();
        $this->assertNotNull($ETags);
        
        $vCalendarFilenames = [];
        foreach($ETags as $vCalendarFilename => $ETag) {
            $vCalendarFilenames[] = basename($vCalendarFilename);
        }
        
        $events = $client->fetchEvents($vCalendarFilenames);
        
        $this->assertNotNull($events);
        foreach($events as $event) {
            $this->assertArrayHasKey('vCalendarFilename', $event);
            $this->assertArrayHasKey('vCalendarRaw', $event);
            $this->assertArrayHasKey('ETag', $event);
        }
    }
    
	#[DataProvider('credentialsProvider')]
    public function testUpdateEvent($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        $ETags = $client->getETags();

        $this->assertNotNull($ETags);
        foreach($ETags as $vCalendarFilename => $ETag) {
            $events = $client->fetchEvents(array(basename($vCalendarFilename)));
            $this->assertCount(1, $events);
            $event = $events[0];

            $this->assertTrue($event['vCalendarFilename'] === basename($vCalendarFilename));

            $new_ETag = $client->updateEvent(basename($vCalendarFilename), $ETag, $event['vCalendarRaw']);
            $this->assertTrue($new_ETag !== false);
        }
    }
    
    public static function credentialsProvider() {
		$configFile = "credentials.json";
        $raw = file_get_contents($configFile);
		if ($raw === false) {
			throw new Exception("couldn't open the file $configFile");
		}
        $credentials = json_decode($raw, true);
		if (is_null($credentials)) {
			throw new Exception("Couldn't read the file $configFile");
		}
        return $credentials["credentials"];
    }
}
