<?php

namespace Joelgrondrup\Tokenauth\ModelAdmin;

use Joelgrondrup\Tokenauth\Model\DeviceToken;
use Joelgrondrup\Tokenauth\Model\PairingToken;
use SilverStripe\Admin\ModelAdmin;

class TokenAdmin extends ModelAdmin
{
    private static $managed_models = [
        DeviceToken::class,
        PairingToken::class,
    ];

    private static $url_segment = 'tokens';

    private static $menu_title = 'Tokens';

    private static $menu_icon_class = 'font-icon-lock';
}
