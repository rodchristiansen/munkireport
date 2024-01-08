Munki reporting module
==============

Provides info about munki

Table Schema
-----
* serial_number (string) serial number of Mac
* runtype (string) one of auto, manualcheck, installwithnologout, checkandinstallatstartup and logoutinstall
* version (string) Munki version
* errors (int) Amount of errors
* warnings (int) Amount of warnings
* manifestname (string) name of the primary manifest
* error_json (string) Error messages in `JSON` format
* warning_json (string) Warning messages in `JSON` format
* starttime (string) DST datetime of when last munki run started
* endtime (string) DST datetime of when last munki run ended
* timestamp (string) datetime of when last munki run

