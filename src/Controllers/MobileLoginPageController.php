<?php

namespace Joelgrondrup\Tokenauth\Controllers;

use Joelgrondrup\Tokenauth\Control\TokenLoginControllerTrait;
use SilverStripe\Control\Controller;

/**
 * Default, zero-setup controller for the token login flow. Extends a plain
 * Controller so it works without a theme.
 *
 * To have the QR page inherit your theme's styling/scripts, don't use this
 * class - instead make your own controller that extends your project's
 * PageController and `use`s {@see TokenLoginControllerTrait}, then point the
 * 'mobilelogin' route at it. See the module README ("Styling / theming").
 */
class MobileLoginPageController extends Controller
{
    use TokenLoginControllerTrait;
}
