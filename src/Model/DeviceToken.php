<?php

namespace Joelgrondrup\Tokenauth\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\RandomGenerator;

/**
 * A long-lived token stored on the mobile device after a successful pairing.
 * The app presents it (over HTTPS) to the "login" endpoint to establish a
 * SilverStripe session again and again until the token expires.
 *
 * Only the SHA-256 hash of the token is ever stored.
 */
class DeviceToken extends DataObject
{
    private static $table_name = 'TokenAuth_DeviceToken';

    /**
     * How long (in seconds) a device token stays valid. Defaults to 30 days.
     */
    private static int $lifetime = 2592000; // 30 * 24 * 60 * 60

    /**
     * When true, every successful login pushes the expiry back to now + lifetime
     * (a "sliding" expiry), so actively used devices stay logged in. When false,
     * the token expires a fixed period after it was created.
     */
    private static bool $sliding_expiry = true;

    private static $db = [
        'TokenHash'  => 'Varchar(64)',
        'DeviceInfo' => 'Text',
        'LastUsed'   => 'Datetime',
        'Expires'    => 'Datetime',
    ];

    private static $has_one = [
        'Member' => Member::class,
    ];

    private static $indexes = [
        'TokenHash' => true,
    ];

    private static $summary_fields = [
        'Member.Title' => 'Member',
        'DeviceInfo'   => 'Device',
        'LastUsed'     => 'Last used',
        'Expires'      => 'Expires',
    ];

    private static $default_sort = 'LastUsed DESC';

    /**
     * Issue a new device token for a member and return the RAW value.
     */
    public static function issue(Member $member, string $deviceInfo = ''): string
    {
        $raw = (new RandomGenerator())->randomToken('sha256');

        $lifetime = (int) static::config()->get('lifetime');
        $now = DBDatetime::now()->getTimestamp();

        $token = static::create();
        $token->TokenHash  = hash('sha256', $raw);
        $token->MemberID   = $member->ID;
        $token->DeviceInfo = $deviceInfo;
        $token->LastUsed   = date('Y-m-d H:i:s', $now);
        $token->Expires    = date('Y-m-d H:i:s', $now + $lifetime);
        $token->write();

        return $raw;
    }

    /**
     * Find a still-valid device token by its raw value, or null.
     */
    public static function findValid(string $rawToken): ?DeviceToken
    {
        $token = static::get()->filter('TokenHash', hash('sha256', $rawToken))->first();

        return ($token && $token->isValid()) ? $token : null;
    }

    public function isValid(): bool
    {
        return strtotime($this->Expires ?? '') > DBDatetime::now()->getTimestamp();
    }

    /**
     * Record that the token was just used to log in, extending the expiry when
     * sliding expiry is enabled.
     */
    public function recordUse(): void
    {
        $now = DBDatetime::now()->getTimestamp();
        $this->LastUsed = date('Y-m-d H:i:s', $now);

        if (static::config()->get('sliding_expiry')) {
            $lifetime = (int) static::config()->get('lifetime');
            $this->Expires = date('Y-m-d H:i:s', $now + $lifetime);
        }

        $this->write();
    }

    /**
     * Housekeeping: remove expired device tokens.
     */
    public static function purgeExpired(): int
    {
        $count = 0;
        $stale = static::get()->filter('Expires:LessThan', DBDatetime::now()->Rfc2822());
        foreach ($stale as $token) {
            $token->delete();
            $count++;
        }
        return $count;
    }

    public function canView($member = null)
    {
        // Members may see their own tokens; CMS admins see all.
        if ($member && $this->MemberID && (int) $member->ID === (int) $this->MemberID) {
            return true;
        }
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
        if ($member && $this->MemberID && (int) $member->ID === (int) $this->MemberID) {
            return true;
        }
        return Permission::check('ADMIN', 'any', $member);
    }
}
