# Changelog

## 1.3.0
- The credit line now appears only on the plugin's own pages — the upload form
  page, the banner management page and the invalid-link page — instead of the
  footer of every page on the site. Ordinary posts and pages never show it, even
  when they display banners.

## 1.2.9
- The footer credit also appears on the plugin's standalone pages — the banner
  management page and the invalid-link page — which build their own document
  and never run `wp_footer`.

## 1.2.8
- Optional second link under each creative: “Fund this banner”, opening that
  banner's own management page. Same style as “Advertise here”; on by default,
  text and alignment configurable, `none` disables it, and the widget and
  shortcode take per-placement overrides (`fund`, `fund_text`, `fund_align`).
- The two links never share a side: when their alignments collide the fund
  link moves to the opposite side. On creatives too narrow for both, they
  stack on separate lines rather than running past the image.
- The disclaimer at the bottom of the management page is now a setting
  (Uploads → Management page disclaimer) and defaults to empty; the fixed
  “balances are never refunded / keep this link private” sentence is gone.

## 1.2.7
- Optional credit line on every public page: “Powered by Crypto Banner Ads
  from Reza Consulting”, with the plugin name linked to the repository and the
  company name linked to reza.consulting. Settings → Display → *Show a small
  credit line in the footer*, on by default.

## 1.2.6
- Responsive slots: `[dwads slot="728x90" slot_mobile="320x100"]`, or
  `data-dwads-slot-mobile` on a raw div, names a second size for narrow
  screens. The loader asks for the size that fits the viewport and swaps it
  when the window crosses the breakpoint (600px by default, filterable with
  `dwads_mobile_breakpoint`).
- If the narrow size has no banner, the wide creative is served so the slot is
  not left empty; `fallback="off"` disables that.
- Widget gains a "Size on phones" option and the admin snippet builder emits
  both attributes.
- A creative that has to shrink to fit its column no longer leaves the slot
  reserving its natural height.

## 1.2.5
- Management links shown right after an upload open in a new tab, so the
  advertiser does not lose the upload screen when checking a banner.

## 1.2.4
- Fitted (auto-fit) banners are saved at 90% quality instead of the WordPress
  default 82, and get a light sharpen pass after the downscale, so resized
  JPEGs stay visibly crisper. Both are filterable: `dwads_editor_quality` and
  `dwads_autofit_sharpen` (0 disables the sharpen).

## 1.2.3
- Optional link underneath each banner: real text in the page flow, aligned to
  the image (left, centre or right), site-wide or overridden per widget and per
  shortcode. Empty slots still render nothing.
- The link falls back to the page containing `[dwads_upload]` when no address is
  set, and `none` switches it off without clearing the other fields.

## 1.2.2
- Warning under the extended public key field, in the wallet help and on the
  Diagnostics screen: deleting plugin data resets the address counter, so
  re-using the same key hands already-funded addresses to new banners.
- Diagnostics gains a starting address index and a check that reports whether
  the next address already has on-chain history.

## 1.2.1
- Near-miss uploads are fitted to the closest allowed size by aspect ratio,
  scaled and centre-cropped, with a hard limit of 8% crop. Original dimensions
  are stored and shown to both advertiser and admin.

## 1.2.0
- Size list carries labels, notes and a "recommended" marker, and drives the
  guidance shown on the upload page.
- Editable upload page introduction.

## 1.1.3
- Added 336x280 and 320x100 to the default sizes.

## 1.1.2
- Added the 250x250 square to the default sizes.

## 1.1.1
- Advertisement label anchored to the creative instead of the slot.

## 1.1.0
- Advertisement label, centring, read-only management page, keywords at upload,
  recoverable management links, advertiser emails and a click log.
- Real client address behind Cloudflare / reverse proxies (de-duplication fix).

## 1.0.3
- Settings are read from the database and verified after save, so a stale object
  cache or a refused write is reported instead of silently redisplaying.

## 1.0.0
- First working build: xpub derivation, pay-per-click balances, snippet loader,
  upload and management pages.
