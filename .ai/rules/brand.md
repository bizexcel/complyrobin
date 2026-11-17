---
paths:
  - app/Filament/**
  - app/Providers/Filament/**
  - app/**/Notifications/**
  - resources/views/**
  - resources/css/**
  - lang/**
  - public/images/brand/**
---

# Brand

Anything a customer sees or reads follows this file. The shareable brand book has the same rules with pictures: https://claude.ai/artifact/5z4qoPcgmokK64VupRujBe

## Colours: one job each, by Filament name
`App\Filament\Brand::colors()` registers every colour on both panels. Components use the Filament name (`->color('danger')`), never a hex value or a Tailwind colour class.

| Role | Filament name | Colour | Used for | Never for |
| --- | --- | --- | --- | --- |
| Primary | `primary` | Robin rust `#C2410C` (`Brand::ROBIN`) | the main button, links, the active menu item | a status |
| Secondary | `secondary` | Ink navy (Slate; `#0F172A` at its darkest) | second-rank buttons, dark headers, the staff panel | |
| Accent | `accent` | Robin's-egg teal `#0F766E`, `#2DD4BF` on dark (`Brand::EGG`) | a chart's second series, illustrations, marketing highlights | buttons, badges, statuses |
| Neutral | `gray` | Slate | text, borders, surfaces | |
| Fine | `success` | Green | "Active", "On time" | |
| Warning | `warning` | Yellow | "Due soon" | |
| Problem | `danger` | Red | "Overdue", "Late", destructive actions | |
| Information | `info` | Blue | neutral notices | |

- **Orange and teal are never a status.** "Due soon" is `warning` (yellow), not amber or `primary`. Always show the status word with its colour.
- **The staff panel's `primary` is Slate**, with an "Admin" label beside the logo, so it never looks like the customer app.
- **Bright orange `#F97316` and cream `#FFF8F1`** belong to the logo and marketing only: never small text, never the app's interface.
- **Palettes are eleven tuned shades, not one hex.** Filament picks a button's shade so its text reaches 4.5:1. From a single hex its generator makes a shade 600 that fails with white text, and buttons fall back to pale with dark text. Shades 600 and 500 of `ROBIN` and `EGG` pass. Check any new or changed palette the same way: white text on 600 and on 500 must reach 4.5:1.
- **Hex values** exist only as `Brand::RUST`, `BRIGHT`, `INK` and `TEAL`, for the places that can't take a Filament name (the staff logo's label, the PDF) and in the mail theme's CSS.

## Logo
The mark is a robin made of two arcs: a dark back and an orange breast, with a beak. The wordmark is "Comply" in ink and "Robin" in the breast's orange, outlined from Plus Jakarta Sans ExtraBold.

| Where | File |
| --- | --- |
| Panels, web pages on light | `public/images/brand/complyrobin-logo.svg` (`Brand::logo()`) |
| Dark grounds | `complyrobin-logo-dark.svg` (`Brand::logo(dark: true)`) |
| Square spaces | `complyrobin-mark.svg`, `complyrobin-mark-dark.svg` |
| Email, PDF | `complyrobin-logo-small.png` (432 × 80, shown at 216 × 40): many email clients and PDF renderers can't show SVG |
| Browser tab, home screens | `public/favicon.svg`, `favicon.ico`, `favicon-96x96.png`, `apple-touch-icon.png`, `web-app-manifest-*.png`, `site.webmanifest` |
| Everything else (JPEG, WebP, AVIF, vector PDF, one-colour, social) | `public/images/brand/kit/` |

- **Never redraw, recolour, retype, stretch or add effects to the logo.** Don't set "ComplyRobin" in type beside the mark; use the logo file.
- **Clear space:** at least a third of the mark's height on every side. **Smallest sizes:** the mark at 16 px, the logo at 24 px high.
- **Panels follow Filament's branding API:** `brandLogo()`, `darkModeBrandLogo()`, `brandLogoHeight('2rem')`, `favicon()`, `font()` and `colors()`. HTML for a panel lives in a Blade view (`resources/views/filament/admin/logo.blade.php`, `resources/views/filament/brand/head.blade.php`), not in a PHP string. There is no custom Filament theme, so Tailwind classes don't work in those views: use inline styles.
- **New logo files** are added in every listed format at once; update the table above and the kit's `README.md` in the same change.

## Type
Plus Jakarta Sans only, through `->font(Brand::FONT)` (served by Filament's default font host). Email falls back to the system font stack. No second typeface.

## Voice and copy
- **Write from the reader's side.** Use the words people say: credentials, keys, rotation, owner. Never the database's words.
- **"You" for the reader.** Sentence case for headings, buttons and menu items: "New credential", "Record rotation".
- **British/Malaysian spelling:** organisation, colour, licence.
- **References and names, never ids:** `CRD-0042`. Dates as `13 Nov 2026`.
- **No emoji** in the product, emails or reports.
- **Say what happened and what to do next,** including in error messages: "ComplyRobin must keep at least one Admin. Make someone else an Admin first."
- **Buttons say exactly what happens:** a verb, no "OK" or "Submit".
- **Never invite anyone to type or paste a secret value.**
- **Warm in emails, empty states and onboarding; calm and exact** when something is overdue, in audit screens and in anything an auditor reads.

## Email and PDF
Emails use the `complyrobin` mail theme (`resources/views/vendor/mail/html`): only the header and the theme are overridden. PDFs embed the small PNG logo as a data URI and use `Brand::RUST` and `Brand::INK` (`pdf.md`).
