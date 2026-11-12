# Changelog

## 1.5.1
- **Reset impressions**, per banner, from the admin banner list. Zeroes the
  impression counter and deletes that banner's stored impression rows, so the
  de-duplication window and the click-rate brake start from a clean slate.
  Clicks charged, the balance, the money already spent and the click log are all
  kept: a reset must never look like a refund or rewrite the billing record.
  Administrators only (`manage_options`), POST with a nonce, and confirmed before
  it runs. The click rate shows "—" until new impressions arrive.

## 1.5.0
- **Impressions are viewable impressions.** The ad script reports when at least
  half the creative has been on screen for a second, and only then does the
  counter move. Turn `impression_viewable` off for the old
  count-on-request behaviour.
- **A click must follow a visible banner.** A token that was never seen, or was
  clicked within `click_min_dwell_ms` (default 400) of becoming visible, is
  redirected but not charged.
- **Real browser metadata required.** `Sec-Fetch-*` presence is checked on the
  beacon and `Sec-Fetch-Mode: navigate` on the click; scrapers send neither.
- **Pointer or key press required** before a click is billable
  (`require_gesture`).
- **Invisible decoy link** in every slot (`honeypot_enabled`); anything that
  follows it is flagged and never billed again.
- **Caps that bound the loss:** charged clicks per visitor per day (3), per
  address per day (15), and per banner per day (50). The hourly per-address cap
  drops from 40 to 10.
- **Click-rate brake:** above `click_rate_ceiling_percent` (20) with at least
  `click_rate_min_impressions` views, the banner stops billing for the rest of
  the rolling day.
- **Fixed a counting bug from 1.4.0** where the de-duplication query matched the
  row the same request had just written, so an impression could never be
  counted at all. The check now only looks at rows this visitor actually saw,
  and never at the current one.
- Schema 1.4.0: the impressions table gains `seen_at` and `armed_at`, plus a
  `(banner_id,seen_at,created_at)` index.
- New endpoints `POST /wp-json/dwads/v1/seen` and `/armed`.
- The management page note now says impressions require the banner to have been
  on screen.

## 1.4.0
- Impression counting is no longer "requests answered". A serve now counts
  once per visitor per de-duplication window (setting
  `impression_dedupe_hours`, default 24, 0 restores the old behaviour), and
  requests with no user agent or a crawler user agent are ignored entirely. The
  counter an advertiser reads now tracks people rather than page loads.
- The management page summary is split into *Clicks charged* (billable),
  *Impressions* (times shown) and *Click rate* (clicks ÷ impressions), with a
  line underneath explaining the counting rules.
- The admin banner list labels the second figure "impressions" instead of
  "shown".
- Schema 1.3.0: the impressions table gains a hashed `visitor` column and a
  `(banner_id,visitor,created_at)` index for the de-duplication query.
- Mobile: the summary tiles are two columns under 560px, three above, and long
  balances wrap inside their tile instead of spilling into the next one.

## 1.3.2
- Fixed the management page washing out on phones in dark mode: the dark rules
  sat before the light ones in the stylesheet, so at equal specificity the light
  background won while the dark text colour still applied — pale text on a white
  card. The dark block now comes last, covers the summary tiles and notices, and
  declares `color-scheme` per branch.
- The upload form brings its own font stack. It used to inherit the theme's
  decorative face — Courier New on Dissent Watch — which ran through the body
  copy and the size list. It is now system sans, and left-aligned even when the
  theme centres its content column.

## 1.3.1
- Removed the "This page is read-only…" paragraph from the banner management
  page. The page is still read-only; it just no longer explains that on every
  visit.

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
