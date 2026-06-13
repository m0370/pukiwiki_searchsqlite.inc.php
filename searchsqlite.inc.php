<?php
/**
 *  searchsqlite.inc.php - SQLite-backed search cache for PukiWiki
 *
 *  PukiWiki標準のサイト内検索 (do_search) は、検索のたびに wiki/*.txt を
 *  個別に開いて正規表現照合するため、ページ数が増えると小ファイルI/Oが
 *  ボトルネックになる。本プラグインは、ページ本文を cache/search.sqlite に
 *  集約した検索用キャッシュを用い、ファイルオープン回数を削減して高速化する。
 *
 *  正本データは従来どおり wiki/*.txt と attach/ であり、SQLiteは削除・再生成
 *  可能な派生キャッシュにすぎない。利用不可・破損・失敗時はすべて標準の
 *  do_search() にフォールバックするため、検索機能が停止することはない。
 *
 *  @license    https://www.gnu.org/licenses/gpl.html GPL v2
 *  @link       https://github.com/m0370/pukiwiki_searchsqlite.inc.php
 *  @version    0.1.0 (2026-06-13)
 *  @package    plugin
 */

// ---- 設定項目 (pukiwiki.ini.php 等で上書き可能) ----

// SQLite検索を有効化するか (0 で常に標準検索へフォールバック)
if (! isset($searchsqlite_enable))            $searchsqlite_enable = 1;
// 添付ファイル名検索を有効化するか
if (! isset($searchsqlite_search_attachments)) $searchsqlite_search_attachments = 1;
// キャッシュ鮮度検査の最小間隔 (秒)。この間隔内は鮮度検査自体を省略する
if (! isset($searchsqlite_check_interval))     $searchsqlite_check_interval = 300;
// 添付ファイル名ヒットの初期表示上限件数 (超過分は「他N件」と表示)
if (! isset($searchsqlite_attach_show_max))    $searchsqlite_attach_show_max = 3;
// デバッグ表示 (1 で検索結果末尾に診断情報を出す)
if (! isset($searchsqlite_debug))              $searchsqlite_debug = 0;

if (! defined('PLUGIN_SEARCHSQLITE_SCHEMA_VERSION'))
	define('PLUGIN_SEARCHSQLITE_SCHEMA_VERSION', '1');
if (! defined('PLUGIN_SEARCHSQLITE_PLUGIN_VERSION'))
	define('PLUGIN_SEARCHSQLITE_PLUGIN_VERSION', '0.1.0');
if (! defined('PLUGIN_SEARCHSQLITE_DB'))
	define('PLUGIN_SEARCHSQLITE_DB',   CACHE_DIR . 'search.sqlite');
if (! defined('PLUGIN_SEARCHSQLITE_LOCK'))
	define('PLUGIN_SEARCHSQLITE_LOCK', CACHE_DIR . 'search.lock');

/**
 * 検索入口。search.inc.php から呼ばれる。do_search() と同じ引数・戻り値。
 *
 * 失敗時は必ず標準 do_search() の戻り値を返す。
 */
function plugin_searchsqlite_do_search($word, $type = 'AND', $non_format = FALSE, $base = '')
{
	global $searchsqlite_enable, $searchsqlite_debug;

	// 無効化されていれば即フォールバック
	if (empty($searchsqlite_enable)) {
		return do_search($word, $type, $non_format, $base);
	}
	// SQLite3 拡張が無ければフォールバック
	if (! class_exists('SQLite3')) {
		return do_search($word, $type, $non_format, $base);
	}

	try {
		$db = plugin_searchsqlite_open();
		if ($db === FALSE) {
			return do_search($word, $type, $non_format, $base);
		}
		plugin_searchsqlite_ensure_fresh($db);
		$result = plugin_searchsqlite_search($db, $word, $type, $non_format, $base);
		$db->close();
		return $result;
	} catch (Exception $e) {
		if (! empty($searchsqlite_debug)) {
			error_log('searchsqlite: ' . $e->getMessage());
		}
		// あらゆる失敗で標準検索へ戻す
		return do_search($word, $type, $non_format, $base);
	}
}

/**
 * SQLite DB を開き、スキーマを初期化して返す。失敗時 FALSE。
 */
function plugin_searchsqlite_open()
{
	// cache/ に書き込めなければ使えない
	if (! is_dir(CACHE_DIR) || ! is_writable(CACHE_DIR)) return FALSE;

	$db = new SQLite3(PLUGIN_SEARCHSQLITE_DB);
	$db->busyTimeout(5000);
	// 再構築中の "database is locked" を避けるため WAL を使う
	@$db->exec('PRAGMA journal_mode = WAL');
	@$db->exec('PRAGMA synchronous = NORMAL');

	plugin_searchsqlite_init_schema($db);
	return $db;
}

/**
 * スキーマ初期化。冪等。
 */
function plugin_searchsqlite_init_schema($db)
{
	$db->exec(
		'CREATE TABLE IF NOT EXISTS pages (' .
		'  page TEXT PRIMARY KEY,' .
		'  filename TEXT NOT NULL,' .
		'  mtime INTEGER NOT NULL,' .
		'  body TEXT NOT NULL' .
		')'
	);
	$db->exec('CREATE INDEX IF NOT EXISTS pages_mtime_idx ON pages(mtime)');
	$db->exec(
		'CREATE TABLE IF NOT EXISTS attachments (' .
		'  page TEXT NOT NULL,' .
		'  filename TEXT NOT NULL,' .
		'  mtime INTEGER,' .
		'  size INTEGER,' .
		'  PRIMARY KEY(page, filename)' .
		')'
	);
	$db->exec('CREATE INDEX IF NOT EXISTS attachments_page_idx ON attachments(page)');
	$db->exec(
		'CREATE TABLE IF NOT EXISTS meta (' .
		'  key TEXT PRIMARY KEY,' .
		'  value TEXT NOT NULL' .
		')'
	);
}

function plugin_searchsqlite_meta_get($db, $key, $default = NULL)
{
	$stmt = $db->prepare('SELECT value FROM meta WHERE key = :k');
	$stmt->bindValue(':k', $key, SQLITE3_TEXT);
	$res = $stmt->execute();
	$row = $res->fetchArray(SQLITE3_ASSOC);
	return ($row === FALSE) ? $default : $row['value'];
}

function plugin_searchsqlite_meta_set($db, $key, $value)
{
	$stmt = $db->prepare('INSERT OR REPLACE INTO meta(key, value) VALUES(:k, :v)');
	$stmt->bindValue(':k', $key, SQLITE3_TEXT);
	$stmt->bindValue(':v', (string)$value, SQLITE3_TEXT);
	$stmt->execute();
}

/**
 * キャッシュの鮮度を確認し、必要なら再構築する。
 *
 * - last_check から $searchsqlite_check_interval 秒以内なら検査自体を省略
 * - スキーマ版が違う / 未構築 なら全再構築
 * - ページ数が変化 / いずれかのファイルが last_rebuild より新しい なら全再構築 (案A)
 */
function plugin_searchsqlite_ensure_fresh($db)
{
	global $searchsqlite_check_interval;

	$now = time();
	$schema = plugin_searchsqlite_meta_get($db, 'schema_version');
	$last_rebuild = (int)plugin_searchsqlite_meta_get($db, 'last_rebuild', 0);
	$last_check   = (int)plugin_searchsqlite_meta_get($db, 'last_check', 0);

	// 一度も構築されていない or スキーマ不一致 → 強制再構築
	if ($schema !== PLUGIN_SEARCHSQLITE_SCHEMA_VERSION || $last_rebuild === 0) {
		plugin_searchsqlite_rebuild($db);
		return;
	}

	// 直近に検査済みなら省略 (低速FSでの mtime 走査を抑える)
	if (($now - $last_check) < $searchsqlite_check_interval) {
		return;
	}

	// 軽い鮮度検査: ページ数と最大mtime
	$files = get_existpages(); // filename(encoded) => page  (readdir のみ)
	$page_count = (int)plugin_searchsqlite_meta_get($db, 'page_count', -1);
	$need_rebuild = (count($files) !== $page_count);

	if (! $need_rebuild) {
		foreach (array_keys($files) as $encoded) {
			$path = DATA_DIR . $encoded;
			$mt = @filemtime($path);
			if ($mt !== FALSE && $mt > $last_rebuild) {
				$need_rebuild = TRUE;
				break;
			}
		}
	}

	if ($need_rebuild) {
		plugin_searchsqlite_rebuild($db);
	} else {
		// 検査だけ通過した場合も last_check を更新して次回検査を間引く
		plugin_searchsqlite_meta_set($db, 'last_check', $now);
	}
}

/**
 * キャッシュ全再構築 (案A)。lock を取れなければ既存DBのまま使う。
 */
function plugin_searchsqlite_rebuild($db)
{
	global $searchsqlite_search_attachments;

	// 多重再構築防止。非ブロッキングで取れなければ他プロセスに任せる
	$lock_fp = @fopen(PLUGIN_SEARCHSQLITE_LOCK, 'c');
	if ($lock_fp === FALSE) {
		// lock ファイルが作れない → 再構築は諦め、既存DBで検索 (or 上位でフォールバック)
		throw new Exception('cannot open lock file');
	}
	if (! flock($lock_fp, LOCK_EX | LOCK_NB)) {
		// 別プロセスが再構築中。既存DBをそのまま使う
		fclose($lock_fp);
		return;
	}

	$now = time();
	try {
		$db->exec('BEGIN');
		$db->exec('DELETE FROM pages');
		$db->exec('DELETE FROM attachments');

		$files = get_existpages(); // encoded filename => page name
		$ins = $db->prepare('INSERT OR REPLACE INTO pages(page, filename, mtime, body) VALUES(:p, :f, :m, :b)');
		$page_count = 0;
		foreach ($files as $encoded => $page) {
			$path = DATA_DIR . $encoded;
			$mtime = @filemtime($path);
			if ($mtime === FALSE) continue;
			// do_search と同一の生本文 (raw=TRUE: CR も保持)
			$body = get_source($page, TRUE, TRUE, TRUE);
			if ($body === FALSE) $body = '';
			$ins->bindValue(':p', $page,   SQLITE3_TEXT);
			$ins->bindValue(':f', $encoded, SQLITE3_TEXT);
			$ins->bindValue(':m', $mtime,  SQLITE3_INTEGER);
			$ins->bindValue(':b', $body,   SQLITE3_TEXT);
			$ins->execute();
			$ins->reset();
			$page_count++;
		}

		$attachment_count = 0;
		if (! empty($searchsqlite_search_attachments)) {
			$attachment_count = plugin_searchsqlite_index_attachments($db);
		}

		plugin_searchsqlite_meta_set($db, 'schema_version', PLUGIN_SEARCHSQLITE_SCHEMA_VERSION);
		plugin_searchsqlite_meta_set($db, 'plugin_version', PLUGIN_SEARCHSQLITE_PLUGIN_VERSION);
		plugin_searchsqlite_meta_set($db, 'last_rebuild', $now);
		plugin_searchsqlite_meta_set($db, 'last_check', $now);
		plugin_searchsqlite_meta_set($db, 'page_count', $page_count);
		plugin_searchsqlite_meta_set($db, 'attachment_count', $attachment_count);

		$db->exec('COMMIT');
	} catch (Exception $e) {
		@$db->exec('ROLLBACK');
		flock($lock_fp, LOCK_UN);
		fclose($lock_fp);
		throw $e; // 上位で標準検索へフォールバック
	}

	flock($lock_fp, LOCK_UN);
	fclose($lock_fp);
}

/**
 * attach/ を走査し添付ファイル名を attachments に登録。登録件数を返す。
 *
 * 添付ファイルは UPLOAD_DIR に `HEXページ名_HEXファイル名` 形式で保存される。
 * 末尾に .N (世代バックアップ) や .log が付くものは現物ではないので除外する。
 * 取得に失敗しても例外を投げず 0 を返す (本文検索は継続する)。
 */
function plugin_searchsqlite_index_attachments($db)
{
	if (! defined('UPLOAD_DIR') || ! is_dir(UPLOAD_DIR)) return 0;
	$dp = @opendir(UPLOAD_DIR);
	if ($dp === FALSE) return 0;

	$ins = $db->prepare('INSERT OR REPLACE INTO attachments(page, filename, mtime, size) VALUES(:p, :f, :m, :s)');
	$count = 0;
	while (($file = readdir($dp)) !== FALSE) {
		// 現物のみ: HEX_HEX (末尾拡張子なし)
		if (! preg_match('/^([0-9A-Fa-f]+)_([0-9A-Fa-f]+)$/', $file, $m)) continue;
		$page = pkwk_hex2bin($m[1]);
		$name = pkwk_hex2bin($m[2]);
		if ($page === '' || $name === '') continue;
		$path = UPLOAD_DIR . $file;
		$ins->bindValue(':p', $page, SQLITE3_TEXT);
		$ins->bindValue(':f', $name, SQLITE3_TEXT);
		$ins->bindValue(':m', @filemtime($path), SQLITE3_INTEGER);
		$ins->bindValue(':s', @filesize($path),  SQLITE3_INTEGER);
		$ins->execute();
		$ins->reset();
		$count++;
	}
	closedir($dp);
	return $count;
}

/**
 * SQLiteキャッシュを用いた検索本体。do_search() の挙動を忠実に再現する。
 *
 * do_search() との差分:
 *  - 本文を get_source() ではなく pages テーブルから取得する (高速化の本体)
 *  - 添付ファイル名も照合対象に含める ($searchsqlite_search_attachments)
 *  - page名ヒットも $search_auth 有効時は check_readable で保護する
 *    (標準 do_search は page名ヒットを認証チェックせず漏れる。安全側に倒す)
 */
function plugin_searchsqlite_search($db, $word, $type, $non_format, $base)
{
	global $whatsnew, $non_list, $search_non_list;
	global $_msg_andresult, $_msg_orresult, $_msg_notfoundresult;
	global $search_auth, $show_passage;
	global $searchsqlite_search_attachments, $searchsqlite_debug;

	$b_type = ($type == 'AND'); // AND:TRUE OR:FALSE
	$keys = get_search_words(preg_split('/\s+/', $word, -1, PREG_SPLIT_NO_EMPTY));
	foreach ($keys as $key => $value) {
		$keys[$key] = '/' . $value . '/S';
	}

	// pages テーブルから全ページの本文を読み込む (1クエリ)
	$bodies = array(); // page => body
	$res = $db->query('SELECT page, body FROM pages');
	while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== FALSE) {
		$bodies[$row['page']] = $row['body'];
	}

	// --- do_search と同じページ集合フィルタ ---
	$pages = array_keys($bodies);
	if ($base != '') {
		$pages = preg_grep('/^' . preg_quote($base, '/') . '/S', $pages);
	}
	if (! $search_non_list) {
		$pages = array_diff($pages, preg_grep('/' . $non_list . '/S', $pages));
	}
	$pages = array_flip($pages);
	unset($pages[$whatsnew]);

	$count = count($pages);

	// 添付ファイル名検索の有無
	$use_attach = ! empty($searchsqlite_search_attachments) && ! $non_format;
	$attach_hits = array(); // page => array(filename, ...)

	foreach (array_keys($pages) as $page) {
		$b_match = FALSE;

		// ページ名照合 (do_search と同様、$non_format では行わない)
		if (! $non_format) {
			foreach ($keys as $key) {
				$b_match = preg_match($key, $page);
				if ($b_type xor $b_match) break; // OR
			}
			if ($b_match) {
				// 標準は名前ヒットを認証チェックしないが、安全側で保護する
				if ($search_auth && ! check_readable($page, false, false)) {
					unset($pages[$page]);
					--$count;
				}
				continue; // ヒット確定
			}
		}

		// 本文の認証チェック (do_search と同位置)
		if ($search_auth && ! check_readable($page, false, false)) {
			unset($pages[$page]);
			--$count;
			continue;
		}

		// 本文照合 (SQLite から取得済みの本文を使用)
		$body = isset($bodies[$page]) ? $bodies[$page] : '';
		foreach ($keys as $key) {
			$b_match = preg_match($key, remove_author_header($body));
			if ($b_type xor $b_match) break; // OR
		}
		if ($b_match) continue; // 本文ヒット確定

		// --- ここまでで未ヒット。添付ファイル名照合 (追加仕様) ---
		if ($use_attach) {
			$names = plugin_searchsqlite_get_attachments($db, $page);
			$hit_names = array();
			foreach ($names as $aname) {
				$am = FALSE;
				foreach ($keys as $key) {
					$am = preg_match($key, $aname);
					if ($b_type xor $am) break;
				}
				if ($am) $hit_names[] = $aname;
			}
			if (! empty($hit_names)) {
				$attach_hits[$page] = $hit_names;
				continue; // 添付名ヒットとして残す
			}
		}

		unset($pages[$page]); // Miss
	}

	if ($non_format) return array_keys($pages);

	// --- 検索結果HTML生成 (do_search 準拠 + 添付名補助表示) ---
	$r_word = rawurlencode($word);
	$s_word = htmlsc($word);
	if (empty($pages)) {
		return str_replace('$1', $s_word,
			str_replace('$3', $count, $_msg_notfoundresult));
	}

	ksort($pages, SORT_STRING);

	$retval = '<ul>' . "\n";
	foreach (array_keys($pages) as $page) {
		$r_page  = rawurlencode($page);
		$s_page  = htmlsc($page);
		$passage = $show_passage ? ' ' . get_passage_html_span($page) : '';
		$retval .= ' <li><a href="' . get_base_uri() . '?cmd=read&amp;page=' .
			$r_page . '&amp;word=' . $r_word . '">' . $s_page .
			'</a>' . $passage;
		if (isset($attach_hits[$page])) {
			$retval .= plugin_searchsqlite_attach_html($attach_hits[$page]);
		}
		$retval .= '</li>' . "\n";
	}
	$retval .= '</ul>' . "\n";

	$retval .= str_replace('$1', $s_word, str_replace('$2', count($pages),
		str_replace('$3', $count, $b_type ? $_msg_andresult : $_msg_orresult)));

	if (! empty($searchsqlite_debug)) {
		$retval .= '<!-- searchsqlite: ' . count($bodies) . ' pages cached -->' . "\n";
	}

	return $retval;
}

/**
 * 指定ページの添付ファイル名一覧を attachments テーブルから取得。
 */
function plugin_searchsqlite_get_attachments($db, $page)
{
	// NOTE: prepared statement is bound to a specific $db connection, and each
	// plugin_searchsqlite_do_search() call opens/closes its own connection.
	// Caching it in a static would reuse a closed handle on the next call, so
	// prepare per call. This runs only for pages that missed name/body match.
	$stmt = $db->prepare('SELECT filename FROM attachments WHERE page = :p');
	$stmt->bindValue(':p', $page, SQLITE3_TEXT);
	$res = $stmt->execute();
	$names = array();
	while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== FALSE) {
		$names[] = $row['filename'];
	}
	$stmt->close();
	return $names;
}

/**
 * 添付ファイル名ヒットの補助表示HTMLを生成 (small クラス)。
 */
function plugin_searchsqlite_attach_html($names)
{
	global $searchsqlite_attach_show_max;
	$max = (int)$searchsqlite_attach_show_max;
	if ($max < 1) $max = 3;

	$total = count($names);
	$shown = array_slice($names, 0, $max);
	$escaped = array();
	foreach ($shown as $n) $escaped[] = htmlsc($n);
	$text = implode(', ', $escaped);
	if ($total > $max) {
		$text .= ' 他' . ($total - $max) . '件';
	}
	return "\n" . '  <div class="small">添付ファイル: ' . $text . '</div>' . "\n ";
}
