<?php

namespace Joelgrondrup\Tokenauth\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\RandomGenerator;

/**
 * A short-lived, one-time-use token embedded in the QR code shown to a
 * logged-in member. The mobile app scans the QR, calls the "pair" endpoint
 * with the raw token, and exchanges it for a long-lived {@link DeviceToken}.
 *
 * Only the SHA-256 hash of the token is ever stored.
 */
class PairingToken extends DataObject
{
    private static $table_name = 'TokenAuth_PairingToken';

    /**
     * How long (in seconds) a freshly generated pairing token stays valid.
     * Kept short on purpose - it only needs to survive a QR scan.
     */
    private static int $lifetime = 120;

    private static $db = [
        'TokenHash' => 'Varchar(64)',
        'Expires'   => 'Datetime',
        'Used'      => 'Boolean',
    ];

    private static $has_one = [
        'Member' => Member::class,
    ];

    private static $indexes = [
        'TokenHash' => true,
    ];

    private static $summary_fields = [
        'Member.Title' => 'Member',
        'Expires'      => 'Expires',
        'Used.Nice'    => 'Used',
    ];

    private static $default_sort = 'Created DESC';

    /**
     * Generate a new pairing token for the given member and return the RAW
     * (un-hashed) token. The raw value is only available here - the database
     * only ever holds its hash.
     */
    public static function generate(Member $member): string
    {
        $raw = (new RandomGenerator())->randomToken('sha256');

        $lifetime = (int) static::config()->get('lifetime');
        $expires = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + $lifetime);

        $token = static::create();
        $token->TokenHash = hash('sha256', $raw);
        $token->MemberID  = $member->ID;
        $token->Expires   = $expires;
        $token->Used      = false;
        $token->write();

        return $raw;
    }

    /**
     * Look up a still-valid pairing token by its raw value, or null.
     */
    public static function findValid(string $rawToken): ?PairingToken
    {
        $token = static::get()->filter('TokenHash', hash('sha256', $rawToken))->first();

        return ($token && $token->isValid()) ? $token : null;
    }

    public function isValid(): bool
    {
        if ($this->Used) {
            return false;
        }

        return strtotime($this->Expires ?? '') > DBDatetime::now()->getTimestamp();
    }

    /**
     * Housekeeping: remove expired / used pairing tokens.
     */
    public static function purgeExpired(): int
    {
        $count = 0;
        $stale = static::get()->filterAny([
            'Used' => true,
            'Expires:LessThan' => DBDatetime::now()->Rfc2822(),
        ]);
        foreach ($stale as $token) {
            $token->delete();
            $count++;
        }
        return $count;
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_LeftAndMain', 'any', $member);
    }

    public function canEdit($member = null)
    {
        return false;
    }

    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    public function canDelete($member = null)
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
