=== Internal Link Suggestions ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: internal links, internal linking, orphaned content, link suggestions, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Suggests internal links while you write, finds older posts that should link to new ones, and reports orphaned content.

== Description ==

Internal Link Suggestions is a free replacement for Link Whisper and the internal linking tools in Yoast SEO Premium.

**While you write.** An Internal Links panel in the block editor (or a box under the classic editor) lists older posts your draft already talks about, each with the words from your draft that would make the link, highlighted in their sentence. Insert link turns those words into the link, right where they are.

**In reverse.** The Link here tab lists older posts that mention the post you're writing but don't link to it yet. Add link puts the link into that older post for you, around its own words, and Undo takes it out again.

**Orphaned content report.** Every post or page with no links from your other posts, with a Find links button that lists the older posts that could fix it.

**Link report.** Every post's links in and out, sortable, with the posts and anchor text behind each number.

How suggestions are made:

* Posts are matched by their title, by link keywords you set in the editor, and by focus keywords from Yoast SEO, Rank Math, SEOPress and All in One SEO.
* A suggestion is only made when your text has words that fit: the post's keyword, its whole title, or a run of its title's words ("running shoes" for "How to Choose Running Shoes for Flat Feet").
* Related posts rank first: posts that share your draft's rarest words, so a word that's on every page of your site never makes two posts look related.
* Links only go into paragraphs and list items: never headings, existing links, buttons, code, captions or shortcodes.

Safe by design:

* Adding a link to an older post changes nothing else in it: only an opening and closing link tag are added around words that are already there. A revision is saved first, and the post's "last modified" date stays the same.
* Posts built with Elementor, Beaver Builder, Bricks, Oxygen or Breakdance are never edited from here (their builders would overwrite the change), and neither is a post someone else has open in the editor.
* Only published, public posts are ever suggested. Password-protected posts are never read for suggestions.
* Every request checks that you can edit the post involved.

Pairs with our free [Link Audit and Reports](https://www.contentpowered.com/wordpress-plugins/link-audit-reports/), which covers the other half: links from your posts to other sites.

Find it under Content Powered > Internal Link Suggestions in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `internal-link-suggestions` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. A background scan reads your published posts (it takes a minute or two on most sites). Its progress shows on Content Powered > Internal Link Suggestions.
4. Open any post in the editor and click the waypoints icon at the top right for the Internal Links panel.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Does it send my content anywhere? =

No. Everything runs on your own server, from an index stored in your database. No AI service or outside API is used.

= What counts as orphaned? =

A published post or page that no other post or page links to from its content. Links in menus, widgets and footers don't count. The front page, the blog page, the privacy policy page and WooCommerce's shop pages are never listed.

= Can I keep a page out of the suggestions? =

Yes. Tick "Don't suggest links to this post" in its Internal Links panel, or use "Not a link target" on the report. It also stays off the orphaned content report.

= What happens to the links when I delete the plugin? =

They stay. They're ordinary links in your posts. Deleting the plugin removes its index tables, settings and its own post meta.

== Changelog ==

See https://github.com/jmsprsns/internal-link-suggestions/releases for the full release history.
