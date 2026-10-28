# Internal Link Suggestions

Suggests internal links while you write, finds older posts that should link to new ones, and reports orphaned content.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/). A free replacement for Link Whisper and Yoast SEO Premium's internal linking tools, and the other half of [Link Audit and Reports](https://www.contentpowered.com/wordpress-plugins/link-audit-reports/) (which covers links to other sites).

## What it does

- **Link to** (while you write): an Internal Links sidebar in the block editor, or a box under the classic editor, lists older posts your draft already talks about. Each comes with the words from your draft that would carry the link, highlighted in their sentence. Insert link wraps those words in the link, in the block they're in.
- **Link here** (in reverse): older posts that mention this post's title or keywords but don't link to it yet. Add link edits that older post on the server; Undo takes the link out again.
- **Orphaned Content**: every published post or page nothing links to, with Find links to fix it in place.
- **Link Report**: links in and out for every post, sortable, with the posts and anchor text behind each number.
- **Added Links**: a log of every link the plugin added to an older post, with Undo.

## How matching works

The plugin keeps an index (four tables, `{prefix}ils_*`) of every published post of the included types: its title, keywords, word count, its 60 most telling words, and the internal links between posts. A background scan builds it after activation (WP-Cron, plus the report page while it's open), and each post is re-read when it's saved.

- **Related posts**: the draft's words, weighted by how rare they are across the site (words in a third of all posts are ignored), are matched against each post's top words in one SQL query.
- **Anchor text**: a related post is only suggested when the draft has words that fit it: its keyword (the plugin's own link keywords, or the focus keyword from Yoast SEO, Rank Math, SEOPress or All in One SEO), its whole title, or a run of at least two of its title's words (stopwords may sit between them, punctuation may not). Words are compared lowercase with plurals and possessives folded, and "weak" words like "guide" or "tips" can't make a match alone.
- **Where links go**: only text in paragraphs, list items, quotes and classic content, never inside headings, existing links, code, buttons, captions or a shortcode's tag. In block content, only blocks whose text is saved as HTML (so adding a link never invalidates a block).

## Safety rules

- Adding a link to an older post finds the words again in its saved HTML (`Internal_Link_Suggestions_Linker::locate()`, which maps decoded text back to raw byte offsets) and inserts only `<a href="...">` and `</a>` around them. Nothing else in the post changes.
- kses still runs on that save, then a `wp_insert_post_data` filter puts back the plugin's content for that one post (running the whole post through kses as a user without `unfiltered_html` would strip embeds an administrator added). The only new markup is the plugin's own tag with an `esc_url()`'d permalink of a published post; the words are the post's own bytes.
- A revision is saved first if the post has none, and `post_modified` is kept.
- Undo is exact when the post hasn't changed since; otherwise it only removes the link when it appears exactly once.
- Posts built with Elementor, Beaver Builder, Bricks, Oxygen or Breakdance, and posts someone else has open in the editor, are never edited.
- Every REST route checks `edit_post` for the post involved (the scan route checks `manage_options`). Suggestions only include published, non-password-protected posts. Editor content sent for analysis is never stored.
- Everything from the server is put on the page as text nodes; the only HTML injected is the bundled SVG icons.

## Filters

| Filter | What it changes |
|---|---|
| `internal_link_suggestions_post_types` | Post types that get and are suggestions |
| `internal_link_suggestions_index_post` | Whether one post is indexed |
| `internal_link_suggestions_stopwords` | Words never matched on |
| `internal_link_suggestions_blocks` | Blocks whose text may get links (only add blocks whose text is saved as HTML) |
| `internal_link_suggestions_always_linked` | Posts never listed as orphaned |

## Automatic updates

Updates come from https://github.com/jmsprsns/internal-link-suggestions through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `internal-link-suggestions.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `internal-link-suggestions.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'INTERNAL_LINK_SUGGESTIONS_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'INTERNAL_LINK_SUGGESTIONS_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
internal-link-suggestions.php                       Plugin header, constants, boot, activation
uninstall.php                                       Drops the tables, options and post meta
readme.txt                                          WordPress readme; feeds the "View details" popup
includes/
  class-internal-link-suggestions-plugin.php        Main singleton, settings, post types
  class-internal-link-suggestions-text.php          HTML tokenizer, linkable text, word keys, sentences
  class-internal-link-suggestions-db.php            The four index tables and every query
  class-internal-link-suggestions-indexer.php       Index on save, link resolving, background scan
  class-internal-link-suggestions-matcher.php       Outbound and inbound suggestions
  class-internal-link-suggestions-linker.php        Adding a link to an older post, and undoing it
  class-internal-link-suggestions-rest.php          REST routes for the editor and the report
  class-internal-link-suggestions-editor.php        Block editor sidebar and classic editor box
  class-internal-link-suggestions-admin.php         Content Powered > Internal Link Suggestions page
  class-internal-link-suggestions-list-table.php    Orphaned Content and Link Report tables
  class-internal-link-suggestions-menu.php          Shared top-level "Content Powered" menu
  class-internal-link-suggestions-feedback.php      Shared Submit Feedback and Report a Bug pages
  class-internal-link-suggestions-updater.php       GitHub update checks (PUC wiring)
  class-internal-link-suggestions-icons.php         Inline Lucide icon helper
assets/
  common.js                                         Requests, suggestion cards, wrapping a link around words
  editor.js, classic.js                             Block editor sidebar, classic editor box
  admin.js, admin.css                               The report page
  editor.css                                        Suggestion cards (editor and report)
  icon.svg                                          Icon on the update screens
  content-powered.svg                               Sumo logo for the Content Powered menu
icons/                                              Lucide icons used by the plugin (plus LICENSE)
plugin-update-checker/                              Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-internal-link-suggestions-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-internal-link-suggestions-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. Print one inline with:

```php
echo Internal_Link_Suggestions_Icons::get( 'package', 20 );           // name, size in px
echo Internal_Link_Suggestions_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
