<?php

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

class PairingToken extends DataObject
{
    private static $db = [
        'TokenHash' => 'Varchar(255)',
        'Expires' => 'Datetime',
    ];

    private static $has_one = [
        'Member' => Member::class,
    ];

    public function isValid(): bool
    {
        return $this->Expires > DBDatetime::now()->Rfc2822();
    }
}
