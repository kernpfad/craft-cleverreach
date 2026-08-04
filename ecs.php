<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);
    // craftcms/ecs ships CRAFT_CMS_4 as the latest set; used for Craft 5 projects too.
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
