<?php

use SilverStripe\ORM\DataObject;

class DeviceToken extends DataObject
{
    private static $db = [
        'TokenHash' => 'Varchar(255)', // Store hashed version of token
        'DeviceInfo' => 'Text',        // Optional device metadata
        'LastUsed' => 'Datetime',
    ];

    private static $has_one = [
        'Member' => Member::class,
    ];
}
