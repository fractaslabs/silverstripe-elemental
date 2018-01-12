<?php

namespace DNADesign\Elemental\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

class ElementalLeftAndMainExtension extends Extension
{
    public function init()
    {
        Requirements::css("dnadesign/silverstripe-elemental:client/dist/styles/bundle.css");
    }
}
