<?php
/**
 * Plugin Name: WebMaster Related
 * Plugin URI: https://github.com/nomidua/webmaster-related
 * Update URI: https://github.com/nomidua/webmaster-related
 * Description: Блок «Читайте также» внутри текста записи: 1–2 вставки со ссылкой и миниатюрой, подбор по меткам и рубрике, кеш подборки в записи, без JavaScript.
 * Version: 1.4
 * Author: nomidua
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: webmaster-related
 */

if (!defined('ABSPATH')) exit;

define('WMR_VERSION', '1.4');
define('WMR_FILE', __FILE__);
define('WMR_REPO', 'nomidua/webmaster-related');

/* ------------------------------------------------------------------ */
/* Настройки                                                           */
/* ------------------------------------------------------------------ */

function wmr_defaults() {
	$uk = strpos((string) get_locale(), 'uk') === 0;
	return [
		'enabled'       => 1,
		'label_ru'      => 'Читайте также',
		'label_uk'      => 'Читайте також',
		'default_lang'  => $uk ? 'uk' : 'ru',
		'two_from'      => 5000,
		'min_par'       => 4,
		'days'          => 30,
		'match_lang'    => 1,
		'excl_cats'     => [],
		'excl_tags'     => '',
		'accent'        => '',
		'line'          => 4,
		'hover'         => 'zoom',
		'thumb'         => 1,
		'ratio'         => '4:3',
		'img_w'         => 96,
		'img_h'         => 72,
		'bg'            => '',
		'css'           => wmr_css_skeleton(),
	];
}

function wmr_css_skeleton() {
	return ".wmr-related {}\n.wmr-related .wmr-link {}\n.wmr-related .wmr-thumb {}\n.wmr-related .wmr-thumb img {}\n.wmr-related .wmr-body {}\n.wmr-related .wmr-label {}\n.wmr-related .wmr-title {}\n.wmr-related .wmr-link:hover .wmr-title {}";
}

function wmr_opt($key = null) {
	static $o = null;
	if ($o === null) {
		$saved = get_option('wmr_settings', []);
		$o = array_merge(wmr_defaults(), is_array($saved) ? $saved : []);
	}
	return $key === null ? $o : ($o[$key] ?? null);
}

/* Тексты админки: язык пользователя (uk / ru) */
function wmr_t($ru, $uk) {
	return strpos((string) get_user_locale(), 'uk') === 0 ? $uk : $ru;
}

/* ------------------------------------------------------------------ */
/* Язык записи                                                         */
/* ------------------------------------------------------------------ */

function wmr_post_lang($id) {
	$l = get_post_meta($id, 'post_lang', true);
	return in_array($l, ['ru', 'uk'], true) ? $l : '';
}

/* ------------------------------------------------------------------ */
/* Исключения                                                          */
/* ------------------------------------------------------------------ */

function wmr_excluded_tag_ids() {
	static $ids = null;
	if ($ids !== null) return $ids;
	$ids = [];
	foreach (array_filter(array_map('trim', explode(',', (string) wmr_opt('excl_tags')))) as $name) {
		$t = get_term_by('name', $name, 'post_tag') ?: get_term_by('slug', sanitize_title($name), 'post_tag');
		if ($t) $ids[] = (int) $t->term_id;
	}
	return $ids;
}

function wmr_is_excluded($id) {
	$cats = array_map('intval', (array) wmr_opt('excl_cats'));
	if ($cats && has_term($cats, 'category', $id)) return true;
	$tags = wmr_excluded_tag_ids();
	if ($tags && has_term($tags, 'post_tag', $id)) return true;
	return false;
}

/* ------------------------------------------------------------------ */
/* Подбор записей                                                      */
/* ------------------------------------------------------------------ */

function wmr_query($post, array $args, $days) {
	$q = [
		'post_type'              => $post->post_type,
		'post_status'            => 'publish',
		'post__not_in'           => [$post->ID],
		'posts_per_page'         => 40,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'suppress_filters'       => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => true,
	];
	if ($days > 0) $q['date_query'] = [['after' => gmdate('Y-m-d H:i:s', strtotime($post->post_date_gmt . ' UTC') - $days * DAY_IN_SECONDS), 'column' => 'post_date_gmt', 'inclusive' => true]];
	return get_posts(array_merge($q, $args));
}

function wmr_term_ids($id, $tax) {
	$t = get_the_terms($id, $tax);
	return is_array($t) ? array_map('intval', wp_list_pluck($t, 'term_id')) : [];
}

function wmr_pick($post) {
	$tags  = wmr_term_ids($post->ID, 'post_tag');
	$cats  = wmr_term_ids($post->ID, 'category');
	$lang  = wmr_opt('match_lang') ? wmr_post_lang($post->ID) : '';
	$text  = (string) $post->post_content;
	$days0 = max(1, (int) wmr_opt('days'));
	$found = [];

	foreach ([$days0, $days0 * 4, 0] as $days) {
		$pool = [];
		if ($tags) foreach (wmr_query($post, ['tag__in' => $tags], $days) as $p) $pool[$p->ID] = $p;
		if ($cats) foreach (wmr_query($post, ['category__in' => $cats], $days) as $p) $pool[$p->ID] = $p;
		$scored = [];
		foreach ($pool as $p) {
			if (wmr_is_excluded($p->ID)) continue;
			$url = get_permalink($p);
			if ($url && strpos($text, untrailingslashit($url)) !== false) continue;
			$s  = 10 * count(array_intersect($tags, wmr_term_ids($p->ID, 'post_tag')));
			$s += 3 * count(array_intersect($cats, wmr_term_ids($p->ID, 'category')));
			if ($lang && wmr_post_lang($p->ID) === $lang) $s += 100;
			if (has_post_thumbnail($p->ID)) $s += 1;
			$scored[] = [$s, strtotime($p->post_date_gmt), $p->ID];
		}
		usort($scored, function ($a, $b) { return $b[0] <=> $a[0] ?: $b[1] <=> $a[1]; });
		foreach ($scored as $r) {
			if (!in_array($r[2], $found, true)) $found[] = $r[2];
			if (count($found) >= 2) break 2;
		}
	}
	return $found;
}

function wmr_get_ids($post) {
	$ids  = get_post_meta($post->ID, '_wmr_ids', true);
	$time = (int) get_post_meta($post->ID, '_wmr_time', true);
	$ok   = is_array($ids) && $time > time() - WEEK_IN_SECONDS;
	if ($ok) foreach ($ids as $id) if (get_post_status($id) !== 'publish') { $ok = false; break; }
	if (!$ok) {
		$ids = wmr_pick($post);
		update_post_meta($post->ID, '_wmr_ids', $ids);
		update_post_meta($post->ID, '_wmr_time', time());
	}
	return array_map('intval', $ids);
}

/* Сброс подборки при сохранении записи */
add_action('save_post', function ($id) {
	if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
	delete_post_meta($id, '_wmr_ids');
	delete_post_meta($id, '_wmr_time');
}, 20);

/* ------------------------------------------------------------------ */
/* Разбор текста: абзацы верхнего уровня                               */
/* ------------------------------------------------------------------ */

function wmr_top_nodes($html) {
	$void  = ['br', 'img', 'hr', 'input', 'source', 'wbr', 'meta', 'link', 'col', 'area', 'embed', 'track', 'param'];
	$stack = [];
	$nodes = [];
	$start = null;
	if (!preg_match_all('~<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(/?)>|<!--.*?-->~s', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return null;
	foreach ($m as $t) {
		if (!isset($t[2]) || $t[2][0] === '') continue;
		$tag = strtolower($t[2][0]);
		if (in_array($tag, $void, true) || $t[3][0] === '/') {
			if (!$stack) $nodes[] = ['tag' => $tag, 's' => $t[0][1], 'e' => $t[0][1] + strlen($t[0][0])];
			continue;
		}
		if ($t[1][0] === '') {
			if (!$stack) $start = $t[0][1];
			$stack[] = $tag;
		} else {
			if (!$stack || end($stack) !== $tag) return null;
			array_pop($stack);
			if (!$stack) $nodes[] = ['tag' => $tag, 's' => $start, 'e' => $t[0][1] + strlen($t[0][0])];
		}
	}
	return $stack ? null : $nodes;
}

function wmr_is_text_p($html, $n) {
	if ($n['tag'] !== 'p') return false;
	$in = substr($html, $n['s'], $n['e'] - $n['s']);
	if (preg_match('~<(img|iframe|video|audio|object|embed|figure|script)\b~i', $in)) return false;
	if (preg_match('~style="[^"]*padding-left:\s*40px~i', $in)) return false;
	return mb_strlen(trim(wp_strip_all_tags($in))) >= 40;
}

/* Следующий узел: абзац без медиа или подзаголовок h2–h4 */
function wmr_next_ok($html, $n) {
	if (in_array($n['tag'], ['h2', 'h3', 'h4'], true)) return true;
	if ($n['tag'] !== 'p') return false;
	$in = substr($html, $n['s'], $n['e'] - $n['s']);
	if (preg_match('~<(img|iframe|video|audio|object|embed|figure|script)\b~i', $in)) return false;
	if (preg_match('~style="[^"]*padding-left:\s*40px~i', $in)) return false;
	return trim(wp_strip_all_tags($in)) !== '';
}

/* Точки вставки: после текстового абзаца, перед абзацем или подзаголовком; не в первых и последних 2 */
function wmr_points($html, $want) {
	$nodes = wmr_top_nodes($html);
	if (!$nodes) return [];
	$total = mb_strlen(wp_strip_all_tags($html));
	if ($total < 1) return [];
	$pars = 0;
	foreach ($nodes as $n) if (wmr_is_text_p($html, $n)) $pars++;
	if ($pars < max(4, (int) wmr_opt('min_par'))) return [];

	$cand = [];
	$pi   = 0;
	$cnt  = count($nodes);
	for ($i = 0; $i < $cnt - 1; $i++) {
		$isp = wmr_is_text_p($html, $nodes[$i]);
		if ($isp) $pi++;
		if (!$isp || !wmr_next_ok($html, $nodes[$i + 1])) continue;
		if ($pi < 2 || $pi > $pars - 2) continue;
		$gap = substr($html, $nodes[$i]['e'], $nodes[$i + 1]['s'] - $nodes[$i]['e']);
		if (trim(wp_strip_all_tags($gap)) !== '') continue;
		$pos    = $nodes[$i]['e'];
		$cand[] = ['pos' => $pos, 'pi' => $pi, 'share' => mb_strlen(wp_strip_all_tags(substr($html, 0, $pos))) / $total];
	}
	if (!$cand) return [];

	$targets = $want >= 2 ? [0.3, 0.7] : [0.4];
	$picked  = [];
	foreach ($targets as $tg) {
		$best = null;
		foreach ($cand as $c) {
			foreach ($picked as $p) if (abs($p['pi'] - $c['pi']) < 3) continue 2;
			if ($best === null || abs($c['share'] - $tg) < abs($best['share'] - $tg)) $best = $c;
		}
		if ($best) $picked[] = $best;
	}
	return array_column($picked, 'pos');
}

/* ------------------------------------------------------------------ */
/* Вывод                                                               */
/* ------------------------------------------------------------------ */

function wmr_css() {
	$a  = trim((string) wmr_opt('accent'));
	$bg = trim((string) wmr_opt('bg'));
	$v  = ($a !== '' ? '--wmr-accent:' . $a . ';' : '') . ($bg !== '' ? '--wmr-bg:' . $bg . ';' : '') . '--wmr-line:' . max(0, min(10, (int) wmr_opt('line'))) . 'px;';
	$css  = '.wmr-related{' . $v . 'clear:both;margin:28px 0}';
	$css .= '.wmr-related .wmr-link{display:flex;align-items:center;gap:14px;padding:10px 14px 10px 10px;border-left:var(--wmr-line,4px) solid var(--wmr-accent,currentColor);background:var(--wmr-bg,rgba(127,127,127,.08));color:inherit!important;text-decoration:none!important;box-shadow:none!important;border-radius:0 4px 4px 0}';
	[$w, $h] = wmr_thumb_size();
	$mw = (int) round($w * .83);
	$css .= '.wmr-related .wmr-thumb{flex:0 0 ' . $w . 'px;width:' . $w . 'px;height:auto;aspect-ratio:' . $w . '/' . $h . ';overflow:hidden;border-radius:3px;background:rgba(127,127,127,.15)}';
	$css .= '.wmr-related .wmr-thumb img{display:block;width:100%!important;height:100%!important;max-width:none!important;margin:0!important;object-fit:cover;transition:transform .3s ease,filter .3s ease}';
	$css .= '.wmr-related .wmr-body{display:flex;flex-direction:column;gap:4px;min-width:0}';
	$css .= '.wmr-related .wmr-label{font-size:12px;line-height:1.2;text-transform:uppercase;letter-spacing:.04em;opacity:.7}';
	$css .= '.wmr-related .wmr-title{font-weight:700;font-size:.95em;line-height:1.3;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}';
	$css .= '.wmr-related .wmr-link:hover .wmr-title{text-decoration:underline}';
	$fx = ['zoom' => 'transform:scale(1.06)', 'shift' => 'transform:scale(1.08) translateX(3px)', 'dark' => 'filter:brightness(.75)'];
	$h  = (string) wmr_opt('hover');
	if (isset($fx[$h])) $css .= '.wmr-related .wmr-link:hover .wmr-thumb img{' . $fx[$h] . '}';
	$css .= '@media(max-width:767px){.wmr-related .wmr-thumb{flex-basis:' . $mw . 'px;width:' . $mw . 'px}.wmr-related .wmr-link{gap:12px}}';
	$css .= (string) wmr_opt('css');
	return '<style id="wmr-css">' . wp_strip_all_tags($css) . '</style>';
}

/* Размер миниатюры: ширина и высота в px по пропорции из настроек */
function wmr_thumb_size() {
	$w = max(40, min(300, (int) wmr_opt('img_w')));
	$r = ['16:9' => [16, 9], '4:3' => [4, 3], '3:4' => [3, 4], '1:1' => [1, 1]];
	$k = (string) wmr_opt('ratio');
	$h = isset($r[$k]) ? (int) round($w * $r[$k][1] / $r[$k][0]) : max(40, min(400, (int) wmr_opt('img_h')));
	return [$w, $h];
}

function wmr_block($id, $label) {
	$title = get_the_title($id);
	if ($title === '') return '';
	$img = '';
	$tid = get_post_thumbnail_id($id);
	if (wmr_opt('thumb') && $tid && ($src = wp_get_attachment_image_src($tid, 'medium'))) {
		[$w, $h] = wmr_thumb_size();
		$set = wp_get_attachment_image_srcset($tid, 'medium');
		$img = '<span class="wmr-thumb"><img src="' . esc_url($src[0]) . '"' . ($set ? ' srcset="' . esc_attr($set) . '" sizes="' . $w . 'px"' : '') . ' width="' . $w . '" height="' . $h . '" alt="" loading="lazy" decoding="async"></span>';
	}
	return '<div class="wmr-related"><a class="wmr-link" href="' . esc_url(get_permalink($id)) . '">' . $img . '<span class="wmr-body"><span class="wmr-label">' . esc_html($label) . '</span><span class="wmr-title">' . esc_html(wp_strip_all_tags($title)) . '</span></span></a></div>';
}

add_filter('the_content', function ($html) {
	if (!wmr_opt('enabled') || is_admin() || is_feed() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return $html;
	if (!is_singular() || !in_the_loop() || !is_main_query() || doing_filter('get_the_excerpt')) return $html;
	$post = get_post();
	if (!$post || $post->post_type !== 'post' || strpos($html, 'class="wmr-related"') !== false) return $html;
	if (get_post_meta($post->ID, '_wmr_off', true) || wmr_is_excluded($post->ID)) return $html;

	$long   = mb_strlen(wp_strip_all_tags($post->post_content)) >= (int) wmr_opt('two_from');
	$points = wmr_points($html, $long ? 2 : 1);
	if (!$points) return $html;
	$ids = wmr_get_ids($post);
	if (!$ids) return $html;

	$lang  = wmr_post_lang($post->ID) ?: wmr_opt('default_lang');
	$label = $lang === 'uk' ? wmr_opt('label_uk') : wmr_opt('label_ru');
	sort($points);
	$blocks = [];
	foreach ($points as $i => $p) if (isset($ids[$i]) && ($b = wmr_block($ids[$i], $label)) !== '') $blocks[$p] = $b;
	krsort($blocks);
	foreach ($blocks as $pos => $b) $html = substr($html, 0, $pos) . "\n" . $b . "\n" . substr($html, $pos);
	return $blocks ? wmr_css() . $html : $html;
}, 20);

/* ------------------------------------------------------------------ */
/* Галочка в редакторе записи                                          */
/* ------------------------------------------------------------------ */

add_action('add_meta_boxes_post', function () {
	add_meta_box('wmr_box', 'WebMaster Related', function ($post) {
		wp_nonce_field('wmr_box', 'wmr_nonce');
		echo '<label><input type="checkbox" name="wmr_off" value="1"' . checked((bool) get_post_meta($post->ID, '_wmr_off', true), true, false) . '> ' . esc_html(wmr_t('Без «Читайте также»', 'Без «Читайте також»')) . '</label>';
	}, 'post', 'side', 'low');
});

add_action('save_post_post', function ($id) {
	if (!isset($_POST['wmr_nonce']) || !wp_verify_nonce($_POST['wmr_nonce'], 'wmr_box') || !current_user_can('edit_post', $id)) return;
	if (!empty($_POST['wmr_off'])) update_post_meta($id, '_wmr_off', 1); else delete_post_meta($id, '_wmr_off');
});

/* ------------------------------------------------------------------ */
/* Страница настроек                                                   */
/* ------------------------------------------------------------------ */

add_action('admin_menu', function () {
	add_options_page('WebMaster Related', 'WebMaster Related', 'manage_options', 'webmaster-related', 'wmr_settings_page');
});

add_filter('plugin_action_links_' . plugin_basename(WMR_FILE), function ($l) {
	array_unshift($l, '<a href="' . esc_url(admin_url('options-general.php?page=webmaster-related')) . '">' . esc_html(wmr_t('Настройки', 'Налаштування')) . '</a>');
	return $l;
});

function wmr_settings_page() {
	if (!current_user_can('manage_options')) return;
	$msg = '';
	if (isset($_POST['wmr_save']) && check_admin_referer('wmr_settings')) {
		$in = wp_unslash($_POST);
		$o  = [
			'enabled'      => empty($in['enabled']) ? 0 : 1,
			'label_ru'     => sanitize_text_field($in['label_ru'] ?? ''),
			'label_uk'     => sanitize_text_field($in['label_uk'] ?? ''),
			'default_lang' => ($in['default_lang'] ?? '') === 'uk' ? 'uk' : 'ru',
			'two_from'     => max(500, (int) ($in['two_from'] ?? 5000)),
			'min_par'      => max(4, (int) ($in['min_par'] ?? 4)),
			'days'         => max(1, (int) ($in['days'] ?? 30)),
			'match_lang'   => empty($in['match_lang']) ? 0 : 1,
			'excl_cats'    => array_map('intval', (array) ($in['excl_cats'] ?? [])),
			'excl_tags'    => sanitize_text_field($in['excl_tags'] ?? ''),
			'accent'       => preg_replace('~[;{}<>]~', '', sanitize_text_field($in['accent'] ?? '')),
			'line'         => max(0, min(10, (int) ($in['line'] ?? 4))),
			'thumb'        => empty($in['thumb']) ? 0 : 1,
			'ratio'        => in_array($in['ratio'] ?? '', ['16:9', '4:3', '3:4', '1:1', 'custom'], true) ? $in['ratio'] : '4:3',
			'img_w'        => max(40, min(300, (int) ($in['img_w'] ?? 96))),
			'img_h'        => max(40, min(400, (int) ($in['img_h'] ?? 72))),
			'hover'        => in_array($in['hover'] ?? '', ['none', 'zoom', 'shift', 'dark'], true) ? $in['hover'] : 'zoom',
			'bg'           => preg_replace('~[;{}<>]~', '', sanitize_text_field($in['bg'] ?? '')),
			'css'          => wp_strip_all_tags($in['css'] ?? ''),
		];
		update_option('wmr_settings', $o, false);
		wmr_flush();
		$msg = wmr_t('Сохранено. Подборки пересчитаются при следующем просмотре записей.', 'Збережено. Добірки перерахуються під час наступного перегляду записів.');
	}
	if (isset($_POST['wmr_flush']) && check_admin_referer('wmr_settings')) {
		wmr_flush();
		$msg = wmr_t('Подборки сброшены.', 'Добірки скинуто.');
	}
	$o = array_merge(wmr_defaults(), (array) get_option('wmr_settings', []));
	$f = function ($label, $field, $desc = '') {
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . ($desc ? '<p class="description">' . esc_html($desc) . '</p>' : '') . '</td></tr>';
	};
	echo '<div class="wrap"><h1>WebMaster Related <span style="font-size:13px;color:#646970">v' . esc_html(WMR_VERSION) . '</span></h1>';
	if ($msg) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
	echo '<form method="post">';
	wp_nonce_field('wmr_settings');
	echo '<table class="form-table" role="presentation">';
	$f(wmr_t('Включено', 'Увімкнено'), '<label><input type="checkbox" name="enabled" value="1"' . checked($o['enabled'], 1, false) . '> ' . esc_html(wmr_t('Вставлять «Читайте также» в записи', 'Вставляти «Читайте також» у записи')) . '</label>');
	$f(wmr_t('Подпись (ru)', 'Підпис (ru)'), '<input type="text" class="regular-text" name="label_ru" value="' . esc_attr($o['label_ru']) . '">');
	$f(wmr_t('Подпись (uk)', 'Підпис (uk)'), '<input type="text" class="regular-text" name="label_uk" value="' . esc_attr($o['label_uk']) . '">');
	$f(wmr_t('Язык по умолчанию', 'Мова за замовчуванням'), '<select name="default_lang"><option value="ru"' . selected($o['default_lang'], 'ru', false) . '>ru</option><option value="uk"' . selected($o['default_lang'], 'uk', false) . '>uk</option></select>', wmr_t('Для записей без поля post_lang.', 'Для записів без поля post_lang.'));
	$f(wmr_t('Две вставки', 'Дві вставки'), '<input type="number" min="500" step="100" class="small-text" name="two_from" value="' . (int) $o['two_from'] . '"> ' . esc_html(wmr_t('знаков текста и больше', 'знаків тексту і більше')), wmr_t('Короче — одна вставка (~40% текста). Длиннее — две (~30% и ~70%).', 'Коротше — одна вставка (~40% тексту). Довше — дві (~30% і ~70%).'));
	$f(wmr_t('Минимум абзацев', 'Мінімум абзаців'), '<input type="number" min="4" class="small-text" name="min_par" value="' . (int) $o['min_par'] . '">', wmr_t('В записях с меньшим числом абзацев блока нет.', 'У записах з меншою кількістю абзаців блоку немає.'));
	$f(wmr_t('Свежесть', 'Свіжість'), '<input type="number" min="1" class="small-text" name="days" value="' . (int) $o['days'] . '"> ' . esc_html(wmr_t('дней', 'днів')), wmr_t('Подбираются записи не старше этого срока от даты текущей записи. Если подходящих нет — поиск расширяется.', 'Підбираються записи не старші за цей строк від дати поточного запису. Якщо підходящих немає — пошук розширюється.'));
	$f(wmr_t('Язык подборки', 'Мова добірки'), '<label><input type="checkbox" name="match_lang" value="1"' . checked($o['match_lang'], 1, false) . '> ' . esc_html(wmr_t('Сначала записи на том же языке (post_lang)', 'Спершу записи тією ж мовою (post_lang)')) . '</label>');
	$cats = get_categories(['hide_empty' => false]);
	$cl = '';
	foreach ($cats as $c) $cl .= '<label style="display:inline-block;min-width:160px;margin:0 12px 6px 0"><input type="checkbox" name="excl_cats[]" value="' . (int) $c->term_id . '"' . (in_array((int) $c->term_id, array_map('intval', (array) $o['excl_cats']), true) ? ' checked' : '') . '> ' . esc_html($c->name) . '</label>';
	$f(wmr_t('Исключить рубрики', 'Виключити рубрики'), $cl, wmr_t('Ни вставок в этих записях, ни ссылок на них.', 'Ні вставок у цих записах, ні посилань на них.'));
	$f(wmr_t('Исключить метки', 'Виключити мітки'), '<input type="text" class="large-text" name="excl_tags" value="' . esc_attr($o['excl_tags']) . '">', wmr_t('Через запятую.', 'Через кому.'));
	$f(wmr_t('Акцентный цвет', 'Акцентний колір'), '<input type="text" class="regular-text" name="accent" value="' . esc_attr($o['accent']) . '" placeholder="currentColor">', wmr_t('Цвет линии слева. Пусто — цвет текста. Можно var(--accent-color-1).', 'Колір лінії ліворуч. Порожньо — колір тексту. Можна var(--accent-color-1).'));
	$f(wmr_t('Линия слева', 'Лінія ліворуч'), '<input type="number" min="0" max="10" class="small-text" name="line" value="' . (int) $o['line'] . '"> px', wmr_t('0 — без линии.', '0 — без лінії.'));
	$hv = ['none' => wmr_t('Без эффекта', 'Без ефекту'), 'zoom' => wmr_t('Увеличение', 'Збільшення'), 'shift' => wmr_t('Сдвиг', 'Зсув'), 'dark' => wmr_t('Затемнение', 'Затемнення')];
	$hs = '';
	foreach ($hv as $k => $l) $hs .= '<option value="' . $k . '"' . selected($o['hover'], $k, false) . '>' . esc_html($l) . '</option>';
	$f(wmr_t('Картинка при наведении', 'Картинка при наведенні'), '<select name="hover">' . $hs . '</select>');
	$rs = '';
	foreach (['16:9' => '16:9', '4:3' => '4:3', '3:4' => '3:4', '1:1' => '1:1', 'custom' => wmr_t('Свой размер', 'Свій розмір')] as $k => $l) $rs .= '<option value="' . esc_attr($k) . '"' . selected($o['ratio'], $k, false) . '>' . esc_html($l) . '</option>';
	$f(wmr_t('Картинка', 'Картинка'), '<label><input type="checkbox" name="thumb" value="1"' . checked($o['thumb'], 1, false) . '> ' . esc_html(wmr_t('Показывать', 'Показувати')) . '</label> &nbsp; <select name="ratio">' . $rs . '</select> &nbsp; ' . esc_html(wmr_t('ширина', 'ширина')) . ' <input type="number" min="40" max="300" class="small-text" name="img_w" value="' . (int) $o['img_w'] . '"> px &nbsp; ' . esc_html(wmr_t('высота', 'висота')) . ' <input type="number" min="40" max="400" class="small-text" name="img_h" value="' . (int) $o['img_h'] . '"> px', wmr_t('Без галочки картинка не выводится вообще (только текст). Высота берётся из пропорции; поле «высота» — только для «Свой размер». На телефоне картинка на 17% меньше.', 'Без галочки картинка не виводиться взагалі (лише текст). Висота береться з пропорції; поле «висота» — лише для «Свій розмір». На телефоні картинка на 17% менша.'));
	$f(wmr_t('Фон', 'Фон'), '<input type="text" class="regular-text" name="bg" value="' . esc_attr($o['bg']) . '" placeholder="rgba(127,127,127,.08)">', wmr_t('Пусто — лёгкий серый.', 'Порожньо — легкий сірий.'));
	$f(wmr_t('Свой CSS', 'Свій CSS'), '<textarea name="css" rows="9" class="large-text code">' . esc_textarea(trim((string) $o['css']) !== '' ? $o['css'] : wmr_css_skeleton()) . '</textarea>', wmr_t('Добавляется после стилей блока. Пустые правила ничего не меняют — впишите свойства в нужный класс.', 'Додається після стилів блоку. Порожні правила нічого не змінюють — впишіть властивості в потрібний клас.'));
	echo '</table><p class="submit"><button type="submit" name="wmr_save" class="button button-primary">' . esc_html(wmr_t('Сохранить', 'Зберегти')) . '</button> <button type="submit" name="wmr_flush" class="button">' . esc_html(wmr_t('Сбросить подборки', 'Скинути добірки')) . '</button></p></form>';
	echo '<p class="description">' . esc_html(wmr_t('Для учёта кликов (GA4 через Google Tag Manager): селектор .wmr-related a, .wmr-related a *', 'Для обліку кліків (GA4 через Google Tag Manager): селектор .wmr-related a, .wmr-related a *')) . '</p></div>';
}

function wmr_flush() {
	delete_post_meta_by_key('_wmr_ids');
	delete_post_meta_by_key('_wmr_time');
}

/* ------------------------------------------------------------------ */
/* Обновления с GitHub (публичный репозиторий, Releases)              */
/* ------------------------------------------------------------------ */

function wmr_release($force = false) {
	$r = get_site_transient('wmr_release');
	if ($r !== false && !$force) return $r;
	$res = wp_remote_get('https://api.github.com/repos/' . WMR_REPO . '/releases/latest', ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WebMaster-Related']]);
	$r = [];
	if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
		$j = json_decode(wp_remote_retrieve_body($res), true);
		if (!empty($j['tag_name'])) {
			$zip = $j['zipball_url'] ?? '';
			foreach ((array) ($j['assets'] ?? []) as $a) if (substr($a['name'] ?? '', -4) === '.zip') { $zip = $a['browser_download_url']; break; }
			$r = ['version' => ltrim($j['tag_name'], 'vV'), 'zip' => $zip, 'notes' => (string) ($j['body'] ?? ''), 'url' => (string) ($j['html_url'] ?? '')];
		}
	}
	set_site_transient('wmr_release', $r, $r ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
	return $r;
}

add_filter('pre_set_site_transient_update_plugins', function ($t) {
	if (!is_object($t)) return $t;
	$r = wmr_release();
	$base = plugin_basename(WMR_FILE);
	if ($r && version_compare($r['version'], WMR_VERSION, '>')) {
		$t->response[$base] = (object) ['slug' => 'webmaster-related', 'plugin' => $base, 'new_version' => $r['version'], 'package' => $r['zip'], 'url' => $r['url']];
	} else {
		unset($t->response[$base]);
		$t->no_update[$base] = (object) ['slug' => 'webmaster-related', 'plugin' => $base, 'new_version' => WMR_VERSION, 'package' => '', 'url' => 'https://github.com/' . WMR_REPO];
	}
	return $t;
});

add_filter('plugins_api', function ($res, $action, $args) {
	if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'webmaster-related') return $res;
	$r = wmr_release();
	return (object) ['name' => 'WebMaster Related', 'slug' => 'webmaster-related', 'version' => $r['version'] ?? WMR_VERSION, 'author' => 'nomidua', 'homepage' => 'https://github.com/' . WMR_REPO, 'requires' => '6.2', 'requires_php' => '7.4', 'sections' => ['changelog' => nl2br(esc_html($r['notes'] ?? ''))], 'download_link' => $r['zip'] ?? ''];
}, 10, 3);

add_filter('upgrader_source_selection', function ($src, $remote, $upgrader, $extra) {
	if (($extra['plugin'] ?? '') !== plugin_basename(WMR_FILE)) return $src;
	$want = trailingslashit($remote) . 'webmaster-related/';
	if (untrailingslashit($src) === untrailingslashit($want)) return $src;
	global $wp_filesystem;
	return $wp_filesystem && $wp_filesystem->move($src, $want, true) ? $want : $src;
}, 10, 4);

add_filter('plugin_row_meta', function ($links, $file) {
	if ($file !== plugin_basename(WMR_FILE)) return $links;
	$links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('plugins.php?wmr_check=1'), 'wmr_check')) . '">' . esc_html(wmr_t('Проверить обновления', 'Перевірити оновлення')) . '</a>';
	return $links;
}, 10, 2);

add_action('admin_init', function () {
	if (empty($_GET['wmr_check']) || !current_user_can('update_plugins') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wmr_check')) return;
	wmr_release(true);
	delete_site_transient('update_plugins');
	wp_safe_redirect(admin_url('plugins.php'));
	exit;
});
