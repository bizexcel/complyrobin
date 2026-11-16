<?php

namespace App\Filament;

use Illuminate\Support\HtmlString;

/**
 * The ComplyRobin look, shared by both panels. Orange is the brand ("robin rust"), never a status: "due soon" is
 * yellow, "overdue" red. The logo files live in public/images/brand.
 */
final class Brand
{
    /**
     * Robin rust. Shade 600 (Filament's buttons and links) is #C2410C and 500 (button hover) #C94712: both keep white
     * text at 4.5:1 or better, which neither Tailwind's orange nor a palette generated from #C2410C does (3.6 and 4.3).
     * 400 is the orange for text on dark backgrounds.
     *
     * @var array<int, string>
     */
    public const array ROBIN = [
        50 => 'oklch(0.977 0.012 43.281)',  // #FFF5F1
        100 => 'oklch(0.947 0.028 42.664)', // #FFE8DF
        200 => 'oklch(0.898 0.055 40.512)', // #FFD2C2
        300 => 'oklch(0.832 0.097 40.368)', // #FFB398
        400 => 'oklch(0.753 0.150 38.388)', // #FE8A65
        500 => 'oklch(0.572 0.175 38.900)', // #C94712
        600 => 'oklch(0.553 0.174 38.402)', // #C2410C
        700 => 'oklch(0.493 0.148 39.655)', // #A33A0D
        800 => 'oklch(0.447 0.123 38.780)', // #8B3618
        900 => 'oklch(0.393 0.099 37.991)', // #712F1A
        950 => 'oklch(0.278 0.072 37.910)', // #45190B
    ];

    /** Plus Jakarta Sans, served by Filament's default font host (Bunny Fonts). */
    public const string FONT = 'Plus Jakarta Sans';

    public static function logo(bool $dark = false): string
    {
        return asset($dark ? 'images/brand/complyrobin-logo-dark.svg' : 'images/brand/complyrobin-logo.svg');
    }

    /**
     * The staff panel's logo: the ComplyRobin logo with an "Admin" label, so staff never mistake it for the app.
     */
    public static function adminLogo(bool $dark = false): HtmlString
    {
        $logo = e(self::logo($dark));
        $label = $dark ? 'color:#F8FAFC;border-color:#475569' : 'color:#0F172A;border-color:#CBD5E1';

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:0.5rem;height:100%">'
            ."<img src=\"{$logo}\" alt=\"ComplyRobin\" style=\"height:100%\">"
            ."<span style=\"font-size:0.75rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;border:1px solid;border-radius:9999px;padding:0.1rem 0.5rem;{$label}\">Admin</span>"
            .'</span>',
        );
    }

    /**
     * Head tags Filament doesn't add itself: the icon iOS uses for a home-screen shortcut.
     */
    public static function headTags(): HtmlString
    {
        return new HtmlString('<link rel="apple-touch-icon" href="'.e(asset('apple-touch-icon.png')).'">');
    }
}
