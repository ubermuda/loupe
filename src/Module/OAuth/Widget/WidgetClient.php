<?php

declare(strict_types=1);

namespace App\Module\OAuth\Widget;

/** The public OAuth client every instance registers for the site-review widget. */
final class WidgetClient
{
    public const string ID = 'loupe-site-review-widget';
    public const string SCOPE = 'site-review';
    public const string CALLBACK_PATH = '/oauth/widget/callback';

    public static function redirectUri(string $appUrl): string
    {
        return rtrim($appUrl, '/').self::CALLBACK_PATH;
    }
}
