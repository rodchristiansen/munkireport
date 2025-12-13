<?php
/**
 * Munkireport Processor
 * 
 * Processes Munki report data from clients.
 * Supports both plist and YAML data formats for future compatibility.
 * 
 * @package munkireport/munkireport
 */

use CFPropertyList\CFPropertyList;
use munkireport\processors\Processor;

// Include the DataParser for YAML support
require_once __DIR__ . '/lib/DataParser.php';
use munkireport\munkireport\lib\DataParser;

class Munkireport_processor extends Processor
{
    /**
     * Error message patterns to suppress.
     * These are typically network-related errors that are not actionable by admins.
     * Add patterns here to filter out specific error messages.
     */
    private $suppressedErrorPatterns = [
        '/\(-1009,.*Internet connection appears to be offline/i',
        '/\(-1001,.*request timed out/i',
        '/\(-1005,.*network connection was lost/i',
        '/\(-1004,.*Could not connect to the server/i',
        '/\(-1003,.*A server with the specified hostname could not be found/i',
    ];

    /**
     * Warning message patterns to suppress.
     * Add patterns here to filter out specific warning messages.
     */
    private $suppressedWarningPatterns = [
        // Add warning patterns here if needed
        // '/pattern/i',
    ];

    public function run($data)
    {
        if (! $data) {
            throw new Exception(
                "Error Processing Request: No data found", 1
            );
        }

        // Use DataParser to handle both plist and YAML formats
        $mylist = DataParser::parse($data);
        if (! $mylist) {
            throw new Exception(
                "Error Processing Request: Could not parse data", 1
            );
        }
        
        $modelData = [
            'serial_number' => $this->serial_number,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Translate plist keys to db keys
        $translate = [
            'ManagedInstallVersion' => 'version',
            'ManifestName' => 'manifestname',
            'RunType' => 'runtype',
            'StartTime' => 'starttime',
            'EndTime' => 'endtime',
        ];

        foreach ($translate as $key => $dbkey) {
            if (array_key_exists($key, $mylist)) {
                $modelData[$dbkey] = $mylist[$key];
            }
        }

        // Parse errors and warnings with filtering
        $errorsWarnings = ['Errors' => 'error_json', 'Warnings' => 'warning_json'];
        foreach ($errorsWarnings as $key => $json) {
            $dbkey = strtolower($key);
            if (isset($mylist[$key]) && is_array($mylist[$key])) {
                // Filter out suppressed messages
                $filteredMessages = $this->_filterMessages(
                    $mylist[$key], 
                    $key === 'Errors' ? $this->suppressedErrorPatterns : $this->suppressedWarningPatterns
                );
                
                // Store count of filtered messages
                $modelData[$dbkey] = count($filteredMessages);

                // Store json of filtered messages
                $modelData[$json] = json_encode($filteredMessages);
            } else {
                // reset
                $modelData[$dbkey] = 0;
                $modelData[$json] = json_encode([]);
            }
        }
        
        $model = Munkireport_model::updateOrCreate(
            ['serial_number' => $this->serial_number], $modelData
        );

        $this->_storeEvents($modelData);

        return $this;
    }

    /**
     * Filter out messages matching suppression patterns.
     * 
     * @param array $messages Array of error/warning messages
     * @param array $patterns Array of regex patterns to suppress
     * @return array Filtered messages
     */
    private function _filterMessages($messages, $patterns)
    {
        if (empty($patterns)) {
            return $messages;
        }

        return array_values(array_filter($messages, function($message) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message)) {
                    return false; // Suppress this message
                }
            }
            return true; // Keep this message
        }));
    }
        
    private function _storeEvents($modelData)
    {
        // Store apropriate event:
        if ($modelData['errors'] == 1) {
            $this->store_event(
                'danger',
                'munki.error',
                json_encode(
                    [
                        'error' => truncate_string(
                            json_decode($modelData['error_json'])[0]
                        )
                    ]
                )
            );
        } elseif ($modelData['errors'] > 1) {
            $this->store_event(
                'danger',
                'munki.error',
                json_encode(['count' => $modelData['errors']])
            );
        } elseif ($modelData['warnings'] == 1) {
            $this->store_event(
                'warning',
                'munki.warning',
                json_encode(
                    [
                        'warning' => truncate_string(
                            json_decode($modelData['warning_json'])[0]
                        )
                    ]
                )
            );
        } elseif ($modelData['warnings'] > 1) {
            $this->store_event(
                'warning',
                'munki.warning',
                json_encode(['count' => $modelData['warnings']])
            );
        } else {
            // Delete event
            $this->delete_event();
        }
    }
}
