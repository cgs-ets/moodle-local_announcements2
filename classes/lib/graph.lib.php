<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   local_announcements2
 * @copyright 2023 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/activities/vendor/autoload.php');
use Microsoft\Graph\Graph;
use Microsoft\Graph\Http;
use Microsoft\Graph\Model;
use Microsoft\Graph\Model\Event;
use GuzzleHttp\Client;

class graph_lib {

    private static Client $tokenClient;
    private static string $appToken;
    private static Graph $appClient;


    public static function getAppOnlyToken(): string {
        // If we already have a token, just return it
        // Tokens are valid for one hour, after that a new token needs to be
        // requested
        if (isset(static::$appToken)) {
            return static::$appToken;
        }

        $tokenClient = new Client();
        $config = get_config('local_announcements2');            
        $clientId = $config->graphclientid;
        $clientSecret = $config->graphclientsecret;
        $tenantId = $config->graphtenantid;

        //echo "<pre>"; 
        //var_export([$clientId, $clientSecret, $tenantId]); 
        //exit;

        // https://learn.microsoft.com/azure/active-directory/develop/v2-oauth2-client-creds-grant-flow
        $tokenRequestUrl = 'https://login.microsoftonline.com/'.$tenantId.'/oauth2/v2.0/token';

        // POST to the /token endpoint
        $tokenResponse = $tokenClient->post($tokenRequestUrl, [
            'form_params' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default'
            ],
            // These options are needed to enable getting
            // the response body from a 4xx response
            'http_errors' => false,
            'curl' => [
                CURLOPT_FAILONERROR => false
            ]
        ]);

        $responseBody = json_decode($tokenResponse->getBody()->getContents());
        if ($tokenResponse->getStatusCode() == 200) {
            // Return the access token
            static::$appToken = $responseBody->access_token;
            return $responseBody->access_token;
        } else {
            $error = isset($responseBody->error) ? $responseBody->error : $tokenResponse->getStatusCode();
            throw new \Exception('Token endpoint returned '.$error, 100);
        }
    }


    // returns one of these: https://github.com/microsoftgraph/msgraph-sdk-php/blob/94aba6eca383e2963440ac463d5ea8f1603a192c/src/Http/GraphCollectionRequest.php
    public static function listCalendarEvents($userPrincipalName, $fromdate, $todate): Http\GraphCollectionRequest {
        $appClient = new Graph();
        $token = static::getAppOnlyToken();
        $appClient->setAccessToken($token);
        
        $queryParams = [
            '$filter' => "start/dateTime gt '$fromdate' and start/dateTime lt '$todate'",
            '$orderby' => 'start/dateTime',
            '$top' => 999,
            '$select' => 'id,subject,start,end,location,body,isOnlineMeeting,onlineMeeting,recurrence,seriesMasterId,type,attendees,organizer,categories,importance,sensitivity,showAs,isCancelled,isAllDay,responseStatus,webLink,createdDateTime,lastModifiedDateTime'
        ];
        
        $requestUrl = '/users/' . $userPrincipalName . '/events?' . http_build_query($queryParams);

        return $appClient->createCollectionRequest('GET', $requestUrl)
                         ->setReturnType(Model\Event::class)
                         ->setPageSize(999);
    }


    // 
    /*
        ----------------------
        CREATE EVENT
        ----------------------
        Request and return details: https://learn.microsoft.com/en-us/graph/api/calendar-post-events?view=graph-rest-1.0&tabs=http
        ------
        JSON
        ------
        {
            "subject": "Let's go for lunch",
            "body": {
            "contentType": "HTML",
            "content": "Does next month work for you?"
            },
            "start": {
                "dateTime": "2019-03-10T12:00:00",
                "timeZone": "Pacific Standard Time"
            },
            "end": {
                "dateTime": "2019-03-10T14:00:00",
                "timeZone": "Pacific Standard Time"
            },
            "location":{
                "displayName":"Harry's Bar"
            },
            "isOnlineMeeting": false,
        }
        ------
        PHP
        ------
        $eventdata = new stdClass();
        $eventdata->subject = "Let's go for lunch";
        $eventdata->body = new stdClass();
        $eventdata->body->contentType = "HTML";
        $eventdata->body->content = "<b>Does</b> next month work for you?";
        $eventdata->start = new stdClass();
        $eventdata->start->dateTime = "2023-07-21T15:11:00";
        $eventdata->start->timeZone = "AUS Eastern Standard Time";
        $eventdata->end = new stdClass();
        $eventdata->end->dateTime = "2023-07-21T16:12:00";
        $eventdata->end->timeZone = "AUS Eastern Standard Time";
        $eventdata->location = new stdClass();
        $eventdata->location->displayName = "Data centre";
        $eventdata->isOnlineMeeting = false;
    */
    public static function createEvent($userPrincipalName, $eventData) {
        $token = static::getAppOnlyToken();
        $appClient = (new Graph())->setAccessToken($token);
        
        $requestUrl = "/users/$userPrincipalName/events";

        // Based on: https://github.com/microsoftgraph/msgraph-sdk-php/blob/dev/tests/Functional/MailTest.php#L49
        $result = $appClient->createRequest("POST", $requestUrl)
                        ->attachBody($eventData)
                        ->setReturnType(Model\Event::class)
                        ->execute();

        return $result;
    }

    /*
        ----------------------
        GET EVENT
        ----------------------
        Request and return details: https://learn.microsoft.com/en-us/graph/api/event-get?view=graph-rest-1.0&tabs=http
        ------
    */
    public static function getEvent($userPrincipalName, $id) {
        $token = static::getAppOnlyToken();
        $appClient = (new Graph())->setAccessToken($token);
        
        $requestUrl = "/users/$userPrincipalName/events/$id";

        // Based on https://github.com/microsoftgraph/msgraph-sdk-php/blob/dev/tests/Functional/MailTest.php#L21
        $result = $appClient->createRequest("GET", $requestUrl)
                            ->setReturnType(Model\Event::class)
                            ->execute();
        return $result;
    }

    /*
        ----------------------
        UPDATE EVENT
        ----------------------
        Request and return details: https://learn.microsoft.com/en-us/graph/api/event-update?view=graph-rest-1.0&tabs=http
        ------
    */
    public static function updateEvent($userPrincipalName, $id, $eventData) {

        if (empty($id)) {
            throw new \Exception("Event ID cannot be empty for update operation");
        }


        $token = static::getAppOnlyToken();
        $appClient = (new Graph())->setAccessToken($token);
        
        //$requestUrl = "/users/$userPrincipalName/events/$id";
        $requestUrl = "/users/" . rawurlencode($userPrincipalName) . "/events/" . rawurlencode($id);

        // Based on https://github.com/microsoftgraph/msgraph-sdk-php/blob/dev/tests/Functional/MailTest.php#L21
        $result = $appClient->createRequest("PATCH", $requestUrl)
                            ->attachBody($eventData)
                            ->setReturnType(Model\Event::class)
                            ->execute();
        return $result;
    }


    /*
        ----------------------
        DELETE EVENT
        ----------------------
        Request and return details: https://learn.microsoft.com/en-us/graph/api/event-delete?view=graph-rest-1.0&tabs=http
        ------
    */
    public static function deleteEvent($userPrincipalName, $id) {
        if (empty($id)) {
            throw new \Exception("Event ID cannot be empty for delete operation");
        }
        
        $token = static::getAppOnlyToken();
        $appClient = (new Graph())->setAccessToken($token);
        
        //$requestUrl = "/users/$userPrincipalName/events/$id";
        $requestUrl = "/users/" . rawurlencode($userPrincipalName) . "/events/" . rawurlencode($id);


        $result = $appClient->createRequest("DELETE", $requestUrl)
                            ->execute();
        return $result;
    }


    /*
        ----------------------
        SEARCH EVENT
        ----------------------
        Request and return details: https://learn.microsoft.com/en-us/graph/api/calendar-list-events?view=graph-rest-1.0&tabs=http#example-3-using-filter-and-orderby-to-get-events-in-a-date-time-range-and-including-their-occurrences
        ------
    */
    public static function searchEvents($userPrincipalName, $eventTitle, $timestamp) {
        $token = static::getAppOnlyToken();
        $appClient = (new Graph())->setAccessToken($token);
    
        // 1. Escape single quotes in title for OData filter
        $escapedTitle = str_replace("'", "''", $eventTitle);

        // 2. Build ISO 8601 UTC datetimes ±30 mins
        $startDateTime = gmdate("Y-m-d\TH:i:s\Z", $timestamp - 1800);
        $endDateTime   = gmdate("Y-m-d\TH:i:s\Z", $timestamp + 1800);

        // 3. Build filter and orderby parameters
        $queryParams = [
            '$filter' => "start/dateTime ge '$startDateTime' and start/dateTime le '$endDateTime' and contains(subject, '$escapedTitle')",
            '$orderby' => 'start/dateTime'
        ];

        // 4. Build full request URL
        $requestUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userPrincipalName) . '/events?' . http_build_query($queryParams);

        $events = $appClient->createCollectionRequest('GET', $requestUrl)
                            ->setReturnType(Model\Event::class)
                            ->setPageSize(50)
                            ->execute();
    
        return $events;
    }
    

    /*
        ----------------------
        GET ALL EVENTS (OPTIMIZED)
        ----------------------
        Optimized version with proper pagination and date range filtering
        ------
    */
    public static function getAllEvents($userPrincipalName, $timestamp, $compare = 'ge', $endTimestamp = null) {
        $token = static::getAppOnlyToken();
        $graph = (new Graph())->setAccessToken($token);
    
        $startDateTime = gmdate("Y-m-d\TH:i:s\Z", $timestamp);
        
        // Build filter with optional end date
        $filter = "start/dateTime $compare '$startDateTime'";
        if ($endTimestamp) {
            $endDateTime = gmdate("Y-m-d\TH:i:s\Z", $endTimestamp);
            $filter .= " and start/dateTime le '$endDateTime'";
        }
        
        $queryParams = [
            '$filter' => $filter,
            '$orderby' => 'start/dateTime',
            '$top' => 999, // Maximum page size for better performance
            '$select' => 'id,subject,start,end,location,body,isOnlineMeeting,onlineMeeting,recurrence,seriesMasterId,type,attendees,organizer,categories,importance,sensitivity,showAs,isCancelled,isAllDay,responseStatus,webLink,createdDateTime,lastModifiedDateTime' // Only fetch needed fields
        ];
    
        $requestUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userPrincipalName) . '/events?' . http_build_query($queryParams);
    
        $allEvents = [];
        $hasMorePages = true;
    
        while ($hasMorePages) {
            try {
                // Single API call with proper collection request
                $eventPage = $graph->createCollectionRequest('GET', $requestUrl)
                                   ->setReturnType(Event::class)
                                   ->setPageSize(999)
                                   ->execute();
    
                // Add events to result
                $allEvents = array_merge($allEvents, $eventPage);
    
                // Check if there are more pages by looking at the response headers
                $response = $graph->createRequest('GET', $requestUrl)->execute();
                $responseBody = $response->getBody();
                
                // Check for nextLink in the response
                if (isset($responseBody['@odata.nextLink'])) {
                    $requestUrl = $responseBody['@odata.nextLink'];
                } else {
                    $hasMorePages = false;
                }
                
            } catch (\Exception $e) {
                // Log error and break to prevent infinite loops
                error_log("Error fetching events from Graph API: " . $e->getMessage());
                break;
            }
        }
    
        return $allEvents;
    }
    

    public static function getSomeEvents($userPrincipalName, $timestamp) {
        $token = static::getAppOnlyToken();
        $graph = (new Graph())->setAccessToken($token);
    
        $startDateTime = gmdate("Y-m-d\TH:i:s\Z", $timestamp);
    
        $queryParams = [
            '$filter' => "start/dateTime ge '$startDateTime'",
            '$orderby' => 'start/dateTime',
            '$top' => 10
        ];
    
        $requestUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userPrincipalName) . '/events?' . http_build_query($queryParams);
    
        $someEvents = [];

        // Fetch events as objects
        $eventPage = $graph->createCollectionRequest('GET', $requestUrl)
            ->setReturnType(Event::class)
            ->execute();

        // Merge the events into the result
        $someEvents = array_merge($someEvents, $eventPage);
    
        return $someEvents;
    }

    /*
        ----------------------
        GET EVENTS BY DATE RANGE (OPTIMIZED)
        ----------------------
        Optimized function for fetching events within a specific date range
        This is more efficient than getAllEvents when you know the end date
        ------
    */
    public static function getEventsByDateRange($userPrincipalName, $startTimestamp, $endTimestamp) {
        $token = static::getAppOnlyToken();
        $graph = (new Graph())->setAccessToken($token);
    
        $startDateTime = gmdate("Y-m-d\TH:i:s\Z", $startTimestamp);
        $endDateTime = gmdate("Y-m-d\TH:i:s\Z", $endTimestamp);
        
        $queryParams = [
            '$filter' => "start/dateTime ge '$startDateTime' and start/dateTime le '$endDateTime'",
            '$orderby' => 'start/dateTime',
            '$top' => 999, // Maximum page size for better performance
            '$select' => 'id,subject,start,end,location,body,isOnlineMeeting,onlineMeeting,recurrence,seriesMasterId,type,attendees,organizer,categories,importance,sensitivity,showAs,isCancelled,isAllDay,responseStatus,webLink,createdDateTime,lastModifiedDateTime'
        ];
    
        $requestUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userPrincipalName) . '/events?' . http_build_query($queryParams);
    
        $allEvents = [];
        $hasMorePages = true;
    
        while ($hasMorePages) {
            try {
                // Single API call with proper collection request
                $eventPage = $graph->createCollectionRequest('GET', $requestUrl)
                                   ->setReturnType(Event::class)
                                   ->setPageSize(999)
                                   ->execute();
    
                // Add events to result
                $allEvents = array_merge($allEvents, $eventPage);
    
                // Check if there are more pages
                $response = $graph->createRequest('GET', $requestUrl)->execute();
                $responseBody = $response->getBody();
                
                if (isset($responseBody['@odata.nextLink'])) {
                    $requestUrl = $responseBody['@odata.nextLink'];
                } else {
                    $hasMorePages = false;
                }
                
            } catch (\Exception $e) {
                error_log("Error fetching events from Graph API: " . $e->getMessage());
                break;
            }
        }
    
        return $allEvents;
    }

    
}