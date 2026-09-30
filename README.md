# Metrika Popular

**English** · [Русский](#русский)

"Most read" posts for WordPress based on **Yandex.Metrika** page views — site-wide, or inside the category of the post being read. No own view counter, no extra writes to your database: page views come from the Metrika Stat API, refreshed by WP-Cron twice a day.

## Features
- `[metrika_popular]` shortcode and a classic widget.
- Automatic scope: on a single post it shows the most read posts of *its* category (current post excluded); elsewhere — the whole site.
- Fallback: if Metrika data does not fill the list, it is topped up by a meta view-counter field (optional), then by the newest posts.
- Works with any permalink structure; a filter lets you map legacy URLs to posts.
- Caches rendered blocks; settings page with status and "Refresh now".
- Translations: English (source), Russian.

## Install
1. Copy the `metrika-popular` folder to `wp-content/plugins/` and activate it.
2. Settings → Metrika Popular: enter the **counter ID** and an **OAuth token** with the `metrika:read` permission ([how to get a token](https://yandex.ru/dev/metrika/en/intro/authorization)).
3. Press **Refresh now**, then put the block anywhere:

```
[metrika_popular]                                   auto scope, 5 posts
[metrika_popular n="3" thumbs="1"]
[metrika_popular scope="site" title="Top of the week"]
[metrika_popular scope="category" cat="sport" n="5"]   cat — slug or ID
```

## Hooks
| Filter | Purpose |
|---|---|
| `mpop_path_to_post_id( $id, $path )` | Map a Metrika URL path to a post ID (return `null` to use `url_to_postid`). |
| `mpop_current_category( $term_id, $post_id )` | Pick which category of a post is used by the `auto` scope. |
| `mpop_ids( $ids, $n, $cat, $exclude )` | Final list of post IDs. |

Example — sites whose URLs are not derived from the post slug (legacy URL tables):

```php
add_filter('mpop_path_to_post_id', function ($id, $path) {
    global $wpdb;
    $row = $wpdb->get_var($wpdb->prepare("SELECT obj_id FROM {$wpdb->prefix}my_urls WHERE path = %s", $path));
    return $row ? (int) $row : $id;
}, 10, 2);
```

Markup: `section.mpop > h3.mpop__title + ol.mpop__list > li.mpop__item > a`. Switch off the built-in CSS in settings to style it yourself.

## Notes
- The token is stored in the `mpop_options` option (like any plugin setting). Use a read-only token.
- Matching Metrika paths to posts runs only in the background job (`url_to_postid` for up to *limit* paths).

License: GPL-2.0-or-later.

---

## Русский

«Самые читаемые» на основе просмотров из **Яндекс.Метрики** — по всему сайту или в рубрике читаемой статьи. Свой счётчик просмотров не нужен, база лишний раз не пишется: просмотры берутся из API Метрики, обновление — WP-Cron два раза в сутки.

**Установка:** папку `metrika-popular` в `wp-content/plugins/`, активировать; Настройки → Metrika Popular: номер счётчика и OAuth-токен с правом `metrika:read`; «Обновить сейчас»; вставить шорткод `[metrika_popular]` (например, в виджет «Текст») или добавить виджет «Самые читаемые (Метрика)».

**Параметры шорткода:** `n` — сколько (1–20), `title` — заголовок, `scope` — `auto` (на статье — её рубрика, иначе весь сайт), `site`, `category` (+ `cat` — слаг или ID), `thumbs="1"` — с миниатюрами.

Если адреса сайта отличаются от адресов в Метрике (старые ЧПУ), подставьте свою карту фильтром `mpop_path_to_post_id`.
