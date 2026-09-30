<?php
/**
 * Plugin Name:       Metrika Popular
 * Plugin URI:        https://github.com/jekfev/metrika-popular
 * Description:       «Самые читаемые» по данным Яндекс.Метрики: по всему сайту или в рубрике открытой статьи. Шорткод [metrika_popular] и виджет. Most-read posts from Yandex.Metrika, site-wide or per category.
 * Version:           0.1.0
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Author:            jekfev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       metrika-popular
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

const MPOP_VERSION = '0.1.0';
const MPOP_OPT     = 'mpop_options';   // настройки
const MPOP_DATA    = 'mpop_data';      // результат последней выгрузки: time, items [[post_id, views], …], error
const MPOP_CRON    = 'mpop_refresh';

/* ---------- Настройки ---------- */

function mpop_options() {
	return wp_parse_args(get_option(MPOP_OPT, []), [
		'counter'       => '',          // номер счётчика Метрики
		'token'         => '',          // OAuth-токен (достаточно права metrika:read)
		'days'          => 7,           // за сколько дней считать
		'limit'         => 300,         // сколько адресов брать из Метрики
		'post_types'    => ['post'],
		'fallback_meta' => '',          // мета-поле со счётчиком просмотров: добивать список, если данных Метрики мало
		'css'           => 1,           // подключать минимальные стили
	]);
}

add_action('plugins_loaded', function () {
	load_plugin_textdomain('metrika-popular', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/* ---------- Выгрузка из Метрики (WP-Cron) ---------- */

register_activation_hook(__FILE__, function () {
	if (!wp_next_scheduled(MPOP_CRON)) wp_schedule_event(time() + 60, 'twicedaily', MPOP_CRON);
});
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook(MPOP_CRON);
});
add_action(MPOP_CRON, 'mpop_refresh');

/**
 * Запрашивает у Метрики просмотры по адресам страниц, сопоставляет адреса с записями и сохраняет список.
 * @return array|WP_Error  сохранённые данные или ошибка
 */
function mpop_refresh() {
	$o = mpop_options();
	if ($o['counter'] === '' || $o['token'] === '') {
		return mpop_fail(new WP_Error('mpop_config', __('Enter the counter number and token.', 'metrika-popular')));
	}
	$days = max(1, min(90, (int) $o['days']));
	$url  = add_query_arg([
		'ids'        => rawurlencode($o['counter']),
		'metrics'    => 'ym:pv:pageviews',
		'dimensions' => 'ym:pv:URLPath',
		'date1'      => $days . 'daysAgo',
		'date2'      => 'yesterday',
		'sort'       => '-ym:pv:pageviews',
		'limit'      => max(10, min(1000, (int) $o['limit'])),
		'accuracy'   => 'medium',
	], 'https://api-metrika.yandex.net/stat/v1/data');
	$r = wp_remote_get($url, ['timeout' => 25, 'headers' => ['Authorization' => 'OAuth ' . $o['token']]]);
	if (is_wp_error($r)) return mpop_fail($r);
	$code = wp_remote_retrieve_response_code($r);
	$j    = json_decode(wp_remote_retrieve_body($r), true);
	if ($code !== 200 || !isset($j['data'])) {
		$msg = is_array($j) && !empty($j['message']) ? $j['message'] : 'HTTP ' . $code;
		return mpop_fail(new WP_Error('mpop_api', 'Yandex.Metrika: ' . $msg));
	}

	$views = [];
	foreach ($j['data'] as $row) {
		$path = (string) ($row['dimensions'][0]['name'] ?? '');
		$pv   = (int) round($row['metrics'][0] ?? 0);
		if ($path === '' || $pv < 1) continue;
		$id = mpop_path_to_post_id($path);
		if ($id) $views[$id] = ($views[$id] ?? 0) + $pv;   // одна запись может иметь несколько адресов
	}
	arsort($views);
	$items = [];
	foreach ($views as $id => $pv) $items[] = [(int) $id, $pv];

	$data = ['time' => time(), 'items' => $items, 'error' => ''];
	update_option(MPOP_DATA, $data, false);
	mpop_bump_cache();
	return $data;
}

function mpop_fail($err) {
	$old = get_option(MPOP_DATA, []);
	$old['error'] = $err->get_error_message();
	update_option(MPOP_DATA, $old, false);
	return $err;
}

/**
 * Адрес страницы (только путь) → ID записи нужного типа.
 * Фильтр `mpop_path_to_post_id` позволяет подставить свою карту адресов (старые ЧПУ, таблица редиректов).
 */
function mpop_path_to_post_id($path) {
	$id = apply_filters('mpop_path_to_post_id', null, $path);
	if ($id === null) $id = url_to_postid(home_url($path));
	if (!$id) {   // нестандартные правила адресов (вложенные рубрики, .html): пробуем по слагу последнего сегмента
		$slug = preg_replace('~\.[a-z0-9]+$~i', '', basename(untrailingslashit($path)));
		$p = $slug !== '' ? get_page_by_path(rawurldecode($slug), OBJECT, (array) mpop_options()['post_types']) : null;
		$id = $p ? $p->ID : 0;
	}
	$id = (int) $id;
	if (!$id) return 0;
	return in_array(get_post_type($id), (array) mpop_options()['post_types'], true) && get_post_status($id) === 'publish' ? $id : 0;
}

/* ---------- Выборка ---------- */

/**
 * ID самых читаемых записей.
 * @param int $n        сколько вернуть
 * @param int $cat      ID рубрики (0 — по всему сайту)
 * @param int $exclude  ID записи, которую не показывать (открытая)
 */
function mpop_ids($n, $cat = 0, $exclude = 0) {
	$o     = mpop_options();
	$items = (array) (get_option(MPOP_DATA, [])['items'] ?? []);
	$ids   = array_values(array_diff(array_map('intval', array_column($items, 0)), [$exclude]));
	$out   = [];
	if ($ids) {
		$q = new WP_Query([
			'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => $n, 'fields' => 'ids',
			'post_type' => $o['post_types'], 'post_status' => 'publish', 'cat' => $cat ?: null,
			'ignore_sticky_posts' => true, 'no_found_rows' => true,
		]);
		$out = array_map('intval', $q->posts);
	}
	// мало данных Метрики — добираем по мета-счётчику просмотров, затем самыми свежими
	$need = $n - count($out);
	if ($need > 0) {
		$args = [
			'posts_per_page' => $need, 'fields' => 'ids', 'post_type' => $o['post_types'], 'post_status' => 'publish',
			'cat' => $cat ?: null, 'post__not_in' => array_merge($out, [$exclude]),
			'ignore_sticky_posts' => true, 'no_found_rows' => true,
		];
		if ($o['fallback_meta'] !== '') { $args['meta_key'] = $o['fallback_meta']; $args['orderby'] = 'meta_value_num'; }
		$out = array_merge($out, array_map('intval', (new WP_Query($args))->posts));
	}
	return apply_filters('mpop_ids', $out, $n, $cat, $exclude);
}

/* ---------- Вывод ---------- */

function mpop_default_title() {
	return __('Most read', 'metrika-popular');
}

/**
 * HTML блока. $a: n, title, scope (auto|site|category), cat (ID или слаг), thumbs (0|1).
 * auto: на странице записи — рубрика этой записи, в остальных местах — весь сайт.
 */
function mpop_render($a) {
	$a = wp_parse_args($a, ['n' => 5, 'title' => mpop_default_title(), 'scope' => 'auto', 'cat' => '', 'thumbs' => 0]);
	$n = max(1, min(20, (int) $a['n']));
	$cat = 0;
	$exclude = 0;
	if ($a['scope'] === 'category' && $a['cat'] !== '') {
		$t = is_numeric($a['cat']) ? get_term((int) $a['cat'], 'category') : get_term_by('slug', $a['cat'], 'category');
		$cat = $t && !is_wp_error($t) ? (int) $t->term_id : 0;
	} elseif ($a['scope'] === 'auto' && is_singular('post')) {
		$exclude = get_queried_object_id();
		$cats = get_the_category($exclude);
		$cat = $cats ? (int) apply_filters('mpop_current_category', $cats[0]->term_id, $exclude) : 0;
	}
	$key = 'mpop_' . md5(wp_json_encode([$n, $cat, $exclude, (int) $a['thumbs'], $a['title'], get_option('mpop_v', 1)]));
	$html = get_transient($key);
	if ($html !== false) return $html;

	$ids = mpop_ids($n, $cat, $exclude);
	$html = '';
	if ($ids) {
		$html = '<section class="mpop">';
		if ($a['title'] !== '') $html .= '<h3 class="mpop__title">' . esc_html($a['title']) . '</h3>';
		$html .= '<ol class="mpop__list">';
		foreach ($ids as $id) {
			$thumb = $a['thumbs'] ? get_the_post_thumbnail($id, 'thumbnail', ['loading' => 'lazy', 'class' => 'mpop__img']) : '';
			$html .= '<li class="mpop__item"><a href="' . esc_url(get_permalink($id)) . '">' . $thumb
				. '<span class="mpop__name">' . esc_html(wp_strip_all_tags(get_the_title($id))) . '</span></a></li>';
		}
		$html .= '</ol></section>';
	}
	set_transient($key, $html, 10 * MINUTE_IN_SECONDS);
	return $html;
}

function mpop_bump_cache() {
	update_option('mpop_v', (int) get_option('mpop_v', 1) + 1, false);
}

add_shortcode('metrika_popular', function ($atts) {
	return mpop_render(shortcode_atts(['n' => 5, 'title' => mpop_default_title(), 'scope' => 'auto', 'cat' => '', 'thumbs' => 0], $atts, 'metrika_popular'));
});

add_action('widgets_init', function () {
	register_widget('MPOP_Widget');
});

class MPOP_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct('mpop_widget', __('Most read (Metrika)', 'metrika-popular'));
	}
	public function widget($args, $i) {
		$html = mpop_render(['n' => $i['n'] ?? 5, 'title' => $i['title'] ?? mpop_default_title(), 'scope' => $i['scope'] ?? 'auto', 'thumbs' => !empty($i['thumbs'])]);
		if ($html !== '') echo $args['before_widget'] . $html . $args['after_widget'];   // phpcs:ignore WordPress.Security.EscapeOutput
	}
	public function form($i) {
		$i = wp_parse_args($i, ['title' => mpop_default_title(), 'n' => 5, 'scope' => 'auto', 'thumbs' => 0]);
		printf('<p><label>%s <input class="widefat" name="%s" value="%s"></label></p>', esc_html__('Title', 'metrika-popular'), esc_attr($this->get_field_name('title')), esc_attr($i['title']));
		printf('<p><label>%s <input type="number" min="1" max="20" name="%s" value="%d"></label></p>', esc_html__('How many', 'metrika-popular'), esc_attr($this->get_field_name('n')), (int) $i['n']);
		printf('<p><label>%s <select name="%s"><option value="auto"%s>%s</option><option value="site"%s>%s</option></select></label></p>',
			esc_html__('Scope', 'metrika-popular'), esc_attr($this->get_field_name('scope')),
			selected($i['scope'], 'auto', false), esc_html__('Category of the current post (elsewhere: whole site)', 'metrika-popular'),
			selected($i['scope'], 'site', false), esc_html__('Whole site', 'metrika-popular'));
		printf('<p><label><input type="checkbox" name="%s" value="1"%s> %s</label></p>', esc_attr($this->get_field_name('thumbs')), checked(!empty($i['thumbs']), true, false), esc_html__('With thumbnails', 'metrika-popular'));
	}
	public function update($new, $old) {
		return [
			'title'  => sanitize_text_field($new['title'] ?? ''),
			'n'      => max(1, min(20, (int) ($new['n'] ?? 5))),
			'scope'  => ($new['scope'] ?? '') === 'site' ? 'site' : 'auto',
			'thumbs' => empty($new['thumbs']) ? 0 : 1,
		];
	}
}

add_action('wp_enqueue_scripts', function () {
	if (!mpop_options()['css']) return;
	wp_register_style('metrika-popular', false, [], MPOP_VERSION);
	wp_enqueue_style('metrika-popular');
	wp_add_inline_style('metrika-popular',
		'.mpop__list{margin:0;padding:0;list-style:none;counter-reset:mpop}'
		. '.mpop__item{counter-increment:mpop;margin:0 0 .7em;padding-left:1.8em;position:relative;line-height:1.35}'
		. '.mpop__item:before{content:counter(mpop);position:absolute;left:0;top:0;font-weight:700;opacity:.5}'
		. '.mpop__item a{text-decoration:none}.mpop__img{display:block;margin-bottom:.3em;max-width:100%;height:auto}');
});

/* ---------- Страница настроек ---------- */

add_action('admin_menu', function () {
	add_options_page('Metrika Popular', 'Metrika Popular', 'manage_options', 'metrika-popular', 'mpop_settings_page');
});

add_action('admin_init', function () {
	register_setting('mpop', MPOP_OPT, ['sanitize_callback' => 'mpop_sanitize']);
});

function mpop_sanitize($in) {
	$old = mpop_options();
	$types = array_values(array_filter(array_map('sanitize_key', (array) ($in['post_types'] ?? []))));
	return [
		'counter'       => preg_replace('~\D~', '', (string) ($in['counter'] ?? '')),
		'token'         => trim((string) ($in['token'] ?? '')) !== '' ? sanitize_text_field($in['token']) : $old['token'],   // пусто — оставить прежний
		'days'          => max(1, min(90, (int) ($in['days'] ?? 7))),
		'limit'         => max(10, min(1000, (int) ($in['limit'] ?? 300))),
		'post_types'    => $types ?: ['post'],
		'fallback_meta' => sanitize_key($in['fallback_meta'] ?? ''),
		'css'           => empty($in['css']) ? 0 : 1,
	];
}

add_action('admin_post_mpop_refresh', function () {
	if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
	check_admin_referer('mpop_refresh');
	mpop_refresh();
	wp_safe_redirect(add_query_arg(['page' => 'metrika-popular', 'refreshed' => 1], admin_url('options-general.php')));
	exit;
});

function mpop_settings_page() {
	if (!current_user_can('manage_options')) return;
	$o = mpop_options();
	$d = get_option(MPOP_DATA, []);
	echo '<div class="wrap"><h1>Metrika Popular</h1>';
	if (!empty($_GET['refreshed'])) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Refresh done.', 'metrika-popular') . '</p></div>';

	echo '<form method="post" action="options.php">';
	settings_fields('mpop');
	echo '<table class="form-table" role="presentation">';
	printf('<tr><th><label for="mpop_counter">%s</label></th><td><input id="mpop_counter" name="%s[counter]" value="%s" class="regular-text" inputmode="numeric"></td></tr>',
		esc_html__('Metrika counter ID', 'metrika-popular'), MPOP_OPT, esc_attr($o['counter']));
	printf('<tr><th><label for="mpop_token">%s</label></th><td><input id="mpop_token" type="password" name="%s[token]" value="" class="regular-text" autocomplete="off" placeholder="%s"><p class="description">%s</p></td></tr>',
		esc_html__('OAuth token', 'metrika-popular'), MPOP_OPT, $o['token'] !== '' ? '••••••••' : '',
		esc_html__('Needs the metrika:read permission. Leave empty to keep the saved token.', 'metrika-popular'));
	printf('<tr><th><label for="mpop_days">%s</label></th><td><input id="mpop_days" type="number" min="1" max="90" name="%s[days]" value="%d" class="small-text"></td></tr>',
		esc_html__('Period, days', 'metrika-popular'), MPOP_OPT, (int) $o['days']);
	printf('<tr><th><label for="mpop_limit">%s</label></th><td><input id="mpop_limit" type="number" min="10" max="1000" name="%s[limit]" value="%d" class="small-text"><p class="description">%s</p></td></tr>',
		esc_html__('Pages from Metrika', 'metrika-popular'), MPOP_OPT, (int) $o['limit'],
		esc_html__('The more categories and the less they are read, the more you need so every category gets enough popular posts.', 'metrika-popular'));
	echo '<tr><th>' . esc_html__('Post types', 'metrika-popular') . '</th><td>';
	foreach (get_post_types(['public' => true], 'objects') as $pt) {
		if ($pt->name === 'attachment') continue;
		printf('<label style="margin-right:1em"><input type="checkbox" name="%s[post_types][]" value="%s"%s> %s</label>', MPOP_OPT, esc_attr($pt->name), checked(in_array($pt->name, $o['post_types'], true), true, false), esc_html($pt->labels->name));
	}
	echo '</td></tr>';
	printf('<tr><th><label for="mpop_meta">%s</label></th><td><input id="mpop_meta" name="%s[fallback_meta]" value="%s" class="regular-text" placeholder="views"><p class="description">%s</p></td></tr>',
		esc_html__('View-count meta field (fallback)', 'metrika-popular'), MPOP_OPT, esc_attr($o['fallback_meta']),
		esc_html__('If Metrika data does not fill the list, add posts with the highest value of this field, then the newest.', 'metrika-popular'));
	printf('<tr><th>%s</th><td><label><input type="checkbox" name="%s[css]" value="1"%s> %s</label></td></tr>',
		esc_html__('Styles', 'metrika-popular'), MPOP_OPT, checked($o['css'], 1, false), esc_html__('Load minimal styles', 'metrika-popular'));
	echo '</table>';
	submit_button();
	echo '</form>';

	echo '<h2>' . esc_html__('Status', 'metrika-popular') . '</h2>';
	if (!empty($d['time'])) {
		printf('<p>%s %s · %s %d</p>', esc_html__('Last refresh:', 'metrika-popular'), esc_html(wp_date('Y-m-d H:i', $d['time'])), esc_html__('posts in the list:', 'metrika-popular'), count((array) ($d['items'] ?? [])));
	} else {
		echo '<p>' . esc_html__('No data yet.', 'metrika-popular') . '</p>';
	}
	if (!empty($d['error'])) echo '<p style="color:#b32d2e"><strong>' . esc_html__('Error:', 'metrika-popular') . '</strong> ' . esc_html($d['error']) . '</p>';
	echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mpop_refresh">';
	wp_nonce_field('mpop_refresh');
	submit_button(__('Refresh now', 'metrika-popular'), 'secondary', 'submit', false);
	echo '</form>';
	echo '<p class="description" style="margin-top:2em"><code>[metrika_popular n="5" scope="auto"]</code> · <code>[metrika_popular scope="category" cat="sport" n="3" thumbs="1"]</code> · <code>[metrika_popular scope="site"]</code></p></div>';
}
