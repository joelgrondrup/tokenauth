<?php

namespace Joelgrondrup\Tokenauth\Task;

use Joelgrondrup\Tokenauth\Model\DeviceToken;
use Joelgrondrup\Tokenauth\Model\PairingToken;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

/**
 * Deletes expired / used pairing tokens and expired device tokens.
 *
 * Run manually at /dev/tasks/tokenauth-purge-expired or on a schedule, e.g.
 * a nightly cron:
 *
 *     0 3 * * *  cd /path/to/site && ./vendor/bin/sake dev/tasks/tokenauth-purge-expired
 */
class PurgeExpiredTokensTask extends BuildTask
{
    private static $segment = 'tokenauth-purge-expired';

    protected $title = 'Token Auth: purge expired tokens';

    protected $description = 'Remove expired/used pairing tokens and expired device tokens.';

    public function run($request)
    {
        $nl = Director::is_cli() ? "\n" : '<br>' . PHP_EOL;

        $pairing = PairingToken::purgeExpired();
        $devices = DeviceToken::purgeExpired();

        echo "Purged {$pairing} pairing token(s){$nl}";
        echo "Purged {$devices} device token(s){$nl}";
        echo "Done.{$nl}";
    }
}
