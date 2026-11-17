<?php

namespace App\Filament;

use Filament\Support\Colors\Color;

/**
 * The ComplyRobin look, shared by both panels: the colours, the font and the logo. Each colour has one job and one
 * Filament name (`.ai/rules/brand.md`); components use the name, never a hex value. The logo files live in
 * public/images/brand.
 */
final class Brand
{
    /**
     * Robin rust, the brand colour. Filament picks a button's shade so its text reaches 4.5:1: 600 (#C2410C) and its
     * hover 500 (#C94712) both pass with white text, so buttons are rust with white text. Tailwind's orange (3.6) and
     * a palette generated from one hex (4.3) fail, and Filament would fall back to a pale button with dark text.
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

    /**
     * Robin's-egg teal, the accent: charts, illustrations and marketing highlights only. Tuned like ROBIN: 600
     * (#0F766E) and 500 (#0E7D72) keep white text above 4.5:1; 400 (#2DD4BF) is the teal for dark grounds.
     *
     * @var array<int, string>
     */
    public const array EGG = [
        50 => 'oklch(0.984 0.014 180.720)',  // #F0FDFA
        100 => 'oklch(0.953 0.050 180.801)', // #CCFBF1
        200 => 'oklch(0.910 0.093 180.426)', // #99F6E4
        300 => 'oklch(0.855 0.125 181.071)', // #5EEAD4
        400 => 'oklch(0.785 0.133 181.912)', // #2DD4BF
        500 => 'oklch(0.531 0.091 183.957)', // #0E7D72
        600 => 'oklch(0.511 0.086 186.391)', // #0F766E
        700 => 'oklch(0.437 0.071 188.216)', // #115E59
        800 => 'oklch(0.386 0.059 188.416)', // #134E4A
        900 => 'oklch(0.336 0.049 188.893)', // #123F3C
        950 => 'oklch(0.277 0.045 192.524)', // #042F2E
    ];

    /** Hex values, for the few places that can't use a Filament colour name: the logo's label, the PDF. */
    public const string RUST = '#C2410C';

    public const string BRIGHT = '#F97316';

    public const string INK = '#0F172A';

    public const string TEAL = '#0F766E';

    /** Plus Jakarta Sans, served by Filament's default font host (Bunny Fonts). */
    public const string FONT = 'Plus Jakarta Sans';

    /**
     * Every Filament colour name and its palette, set explicitly so none falls back to a Filament default.
     * Orange is the brand and teal the accent: neither is ever a status.
     *
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return [
            'primary' => self::ROBIN,
            'secondary' => Color::Slate,
            'accent' => self::EGG,
            'gray' => Color::Slate,
            'success' => Color::Green,
            'warning' => Color::Yellow,
            'danger' => Color::Red,
            'info' => Color::Blue,
        ];
    }

    public static function logo(bool $dark = false): string
    {
        return asset($dark ? 'images/brand/complyrobin-logo-dark.svg' : 'images/brand/complyrobin-logo.svg');
    }
}
