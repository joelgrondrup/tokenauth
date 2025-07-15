<?php

use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Security\RandomGenerator;
use SilverStripe\Security\Security;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use SilverStripe\Control\HTTPResponse;

class MobileLoginPageController extends ContentController{

    
    public function index (){

        return $this->renderWith(["MobileLoginPage"]);

    }

    public function generatepairingtoken(){

        $randomGenerator = new RandomGenerator();
        $rawToken = $randomGenerator->randomToken();

        $hash = hash('sha256', $rawToken);

        PairingToken::create([
            'TokenHash' => $hash,
            'MemberID' => Security::getCurrentUser()->ID,
            'Expires' => DBDatetime::now()->modify('+2 minutes'),
        ])->write();

        $qrUrl = "https://example.com/api/pair-device?token=$rawToken";

        $writer = new PngWriter();

        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $qrUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $result = $builder->build();

        return $result->getDataUri();

    }

}