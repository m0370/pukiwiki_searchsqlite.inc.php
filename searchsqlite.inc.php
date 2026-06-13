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
 *  @version    0.2.0 (2026-06-13)
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

// max_mtime / attachment_enabled による鮮度判定を導入したため 2 に更新。
// テーブル構造は同じだが、既存の search.sqlite を確実に作り直させるために上げる。
if (! defined('PLUGIN_SEARCHSQLITE_SCHEMA_VERSION'))
	define('PLUGIN_SEARCHSQLITE_SCHEMA_VERSION', '2');
if (! defined('PLUGIN_SEARCHSQLITE_PLUGIN_VERSION'))
	define('PLUGIN_SEARCHSQLITE_PLUGIN_VERSION', '0.2.0');
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

	$db = NULL;
	try {
		$db = plugin_searchsqlite_open();
		if ($db === FALSE) {
			return do_search($word, $type, $non_format, $base);
		}
		plugin_searchsqlite_ensure_fresh($db);
		$result = plugin_searchsqlite_search($db, $word, $type, $non_format, $base);
		$db->close();
		return $result;
	} catch (Throwable $e) {
		// Exception だけでなく Error / TypeError も拾う。
		// (破損DBで query()/prepare() が FALSE を返し、その戻り値にメソッドを
		//  呼んだときの TypeError 等も含め、確実に標準検索へ戻す)
		if (! empty($searchsqlite_debug)) {
			error_log('searchsqlite: ' . $e->getMessage());
		}
		if ($db instanceof SQLite3) { @$db->close(); }
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
 * $db->exec() を実行し、失敗 (FALSE) なら例外を投げる。
 * SQLite3 は失敗時に例外ではなく FALSE を返すことがあるため、
 * 書き込みの取りこぼし (欠けたDBを fresh 扱いする事故) を防ぐ。
 */
function plugin_searchsqlite_exec($db, $sql)
{
	if ($db->exec($sql) === FALSE) {
		throw new Exception('exec failed: ' . $db->lastErrorMsg());
	}
}

/**
 * $stmt->execute() を実行し、失敗 (FALSE) なら例外を投げる。
 */
function plugin_searchsqlite_execute($db, $stmt)
{
	if ($stmt->execute() === FALSE) {
		throw new Exception('execute failed: ' . $db->lastErrorMsg());
	}
}

/**
 * スキーマ初期化。冪等。
 */
function plugin_searchsqlite_init_schema($db)
{
	plugin_searchsqlite_exec($db,
		'CREATE TABLE IF NOT EXISTS pages (' .
		'  page TEXT PRIMARY KEY,' .
		'  filename TEXT NOT NULL,' .
		'  mtime INTEGER NOT NULL,' .
		'  body TEXT NOT NULL' .
		')'
	);
	plugin_searchsqlite_exec($db, 'CREATE INDEX IF NOT EXISTS pages_mtime_idx ON pages(mtime)');
	plugin_searchsqlite_exec($db,
		'CREATE TABLE IF NOT EXISTS attachments (' .
		'  page TEXT NOT NULL,' .
		'  filename TEXT NOT NULL,' .
		'  mtime INTEGER,' .
		'  size INTEGER,' .
		'  PRIMARY KEY(page, filename)' .
		')'
	);
	plugin_searchsqlite_exec($db, 'CREATE INDEX IF NOT EXISTS attachments_page_idx ON attachments(page)');
	plugin_searchsqlite_exec($db,
		'CREATE TABLE IF NOT EXISTS meta (' .
		'  key TEXT PRIMARY KEY,' .
		'  value TEXT NOT NULL' .
		')'
	);
}

function plugin_searchsqlite_meta_get($db, $key, $default = NULL)
{
	$stmt = $db->prepare('SELECT value FROM meta WHERE key = :k');
	if ($stmt === FALSE) throw new Exception('prepare(meta_get) failed');
	$stmt->bindValue(':k', $key, SQLITE3_TEXT);
	$res = $stmt->execute();
	if ($res === FALSE) throw new Exception('execute(meta_get) failed');
	$row = $res->fetchArray(SQLITE3_ASSOC);
	return ($row === FALSE) ? $default : $row['value'];
}

function plugin_searchsqlite_meta_set($db, $key, $value)
{
	$stmt = $db->prepare('INSERT OR REPLACE INTO meta(key, value) VALUES(:k, :v)');
	if ($stmt === FALSE) throw new Exception('prepare(meta_set) failed');
	$stmt->bindValue(':k', $key, SQLITE3_TEXT);
	$stmt->bindValue(':v', (string)$value, SQLITE3_TEXT);
	plugin_searchsqlite_execute($db, $stmt);
}

/**
 * キャッシュの鮮度を確認し、必要なら再構築する。
 *
 * - last_check から $searchsqlite_check_interval 秒以内なら検査自体を省略
 * - スキーマ版が違う / 未構築 なら全再構築
 * - ページ/添付の件数が変化、または保存済み max_mtime より新しいファイルがあれば全再構築 (案A)
 */
function plugin_searchsqlite_ensure_fresh($db)
{
	global $searchsqlite_check_interval, $searchsqlite_search_attachments;

	$now = time();
	$schema = plugin_searchsqlite_meta_get($db, 'schema_version');
	$last_rebuild = (int)plugin_searchsqlite_meta_get($db, 'last_rebuild', 0);
	$last_check   = (int)plugin_searchsqlite_meta_get($db, 'last_check', 0);

	// 一度も構築されていない or スキーマ不一致 → 強制再構築。
	// この時点では使えるキャッシュが無いので、再構築できなければ ($required=TRUE)
	// 例外を投げて標準検索へフォールバックする (空DBを検索させない)。
	if ($schema !== PLUGIN_SEARCHSQLITE_SCHEMA_VERSION || $last_rebuild === 0) {
		plugin_searchsqlite_rebuild($db, TRUE);
		return;
	}

	// 添付検索の有効/無効を切り替えたら、検査間隔に関係なく再構築する。
	// (OFFで構築したDBを直後にONにしても添付テーブルが空のままになるのを防ぐ)
	$want_attach   = ! empty($searchsqlite_search_attachments) ? 1 : 0;
	$built_attach  = (int)plugin_searchsqlite_meta_get($db, 'attachment_enabled', 0);
	if ($want_attach !== $built_attach) {
		plugin_searchsqlite_rebuild($db, FALSE);
		return;
	}

	// 直近に検査済みなら省略 (低速FSでの mtime 走査を抑える)
	if (($now - $last_check) < $searchsqlite_check_interval) {
		return;
	}

	// 鮮度検査: 件数の変化、または「保存済みの最大mtime」より新しいファイルがあれば再構築。
	// 壁時計 last_rebuild ではなく実ファイルの最大mtimeを基準にすることで、
	// 再構築と同一秒に更新されたファイルの取りこぼしを防ぐ。
	$stored_max = (int)plugin_searchsqlite_meta_get($db, 'max_mtime', 0);
	$page_count = (int)plugin_searchsqlite_meta_get($db, 'page_count', -1);

	$files = get_existpages(); // filename(encoded) => page  (readdir のみ)
	$need_rebuild = (count($files) !== $page_count);
	if (! $need_rebuild) {
		foreach (array_keys($files) as $encoded) {
			$mt = @filemtime(DATA_DIR . $encoded);
			if ($mt !== FALSE && $mt > $stored_max) {
				$need_rebuild = TRUE;
				break;
			}
		}
	}

	// 添付検索が有効なら attach/ の件数・最大mtimeも見る (添付の追加/削除/更新を反映)
	if (! $need_rebuild && ! empty($searchsqlite_search_attachments)) {
		$attach_count = (int)plugin_searchsqlite_meta_get($db, 'attachment_count', -1);
		$stat = plugin_searchsqlite_scan_attachments();
		if ($stat['count'] !== $attach_count || $stat['max_mtime'] > $stored_max) {
			$need_rebuild = TRUE;
		}
	}

	if ($need_rebuild) {
		// 既存の有効キャッシュがあるので、ロックを取れなければ既存DBで検索してよい ($required=FALSE)
		plugin_searchsqlite_rebuild($db, FALSE);
	} else {
		// 検査だけ通過した場合も last_check を更新して次回検査を間引く
		plugin_searchsqlite_meta_set($db, 'last_check', $now);
	}
}

/**
 * キャッシュ全再構築 (案A)。
 *
 * @param boolean $required TRUE のとき、lock を取れなければ例外を投げる
 *   (使えるキャッシュが無い初回構築時。空DBを検索させず標準検索へ戻すため)。
 *   FALSE のとき、lock を取れなければ既存DBのまま使う (鮮度更新は他プロセスに任せる)。
 */
function plugin_searchsqlite_rebuild($db, $required = FALSE)
{
	global $searchsqlite_search_attachments;

	// 多重再構築防止。非ブロッキングで取れなければ他プロセスに任せる
	$lock_fp = @fopen(PLUGIN_SEARCHSQLITE_LOCK, 'c');
	if ($lock_fp === FALSE) {
		// lock ファイルが作れない (cache 書込不可など) → 標準検索へフォールバック
		throw new Exception('cannot open lock file');
	}
	if (! flock($lock_fp, LOCK_EX | LOCK_NB)) {
		fclose($lock_fp);
		if ($required) {
			// 別プロセスが初回構築中。まだ使えるキャッシュが無いので標準検索へ戻す
			throw new Exception('cache is being built by another process');
		}
		// 既存DBが有効なのでそのまま使う
		return;
	}

	$now = time();
	$max_mtime = 0;
	$attach_enabled = ! empty($searchsqlite_search_attachments) ? 1 : 0;
	try {
		plugin_searchsqlite_exec($db, 'BEGIN');
		plugin_searchsqlite_exec($db, 'DELETE FROM pages');
		plugin_searchsqlite_exec($db, 'DELETE FROM attachments');

		$files = get_existpages(); // encoded filename => page name
		$ins = $db->prepare('INSERT OR REPLACE INTO pages(page, filename, mtime, body) VALUES(:p, :f, :m, :b)');
		if ($ins === FALSE) throw new Exception('prepare(pages) failed');
		$page_count = 0;
		foreach ($files as $encoded => $page) {
			$path = DATA_DIR . $encoded;
			$mtime = @filemtime($path);
			if ($mtime === FALSE) continue;
			if ($mtime > $max_mtime) $max_mtime = $mtime;
			// do_search と同一の生本文 (raw=TRUE: CR も保持)
			$body = get_source($page, TRUE, TRUE, TRUE);
			if ($body === FALSE) $body = '';
			$ins->bindValue(':p', $page,   SQLITE3_TEXT);
			$ins->bindValue(':f', $encoded, SQLITE3_TEXT);
			$ins->bindValue(':m', $mtime,  SQLITE3_INTEGER);
			$ins->bindValue(':b', $body,   SQLITE3_TEXT);
			plugin_searchsqlite_execute($db, $ins);
			$ins->reset();
			$page_count++;
		}

		$attachment_count = 0;
		if ($attach_enabled) {
			$ares = plugin_searchsqlite_index_attachments($db);
			$attachment_count = $ares['count'];
			if ($ares['max_mtime'] > $max_mtime) $max_mtime = $ares['max_mtime'];
		}

		// mtime は秒精度なので、最新ファイルの mtime が「いま」と同じ秒以上のときは、
		// その秒はまだ進行中で信頼できない (読み取り直後に同一秒で再編集される競合が
		// ありうる)。保存する max_mtime を now-1 に切り下げることで、次回検査で
		// ちょうど一度だけ再検証させる (>= を使うと静止系で毎回再構築する無限ループに
		// なるため、この切り下げ方式で同一秒競合を無限ループなしに検出する)。
		$store_max = ($max_mtime >= $now) ? ($now - 1) : $max_mtime;

		plugin_searchsqlite_meta_set($db, 'schema_version', PLUGIN_SEARCHSQLITE_SCHEMA_VERSION);
		plugin_searchsqlite_meta_set($db, 'plugin_version', PLUGIN_SEARCHSQLITE_PLUGIN_VERSION);
		plugin_searchsqlite_meta_set($db, 'last_rebuild', $now);
		plugin_searchsqlite_meta_set($db, 'last_check', $now);
		plugin_searchsqlite_meta_set($db, 'page_count', $page_count);
		plugin_searchsqlite_meta_set($db, 'attachment_count', $attachment_count);
		plugin_searchsqlite_meta_set($db, 'max_mtime', $store_max);
		// このDBが添付ファイルをインデックス済みか。設定変更の検出に使う
		plugin_searchsqlite_meta_set($db, 'attachment_enabled', $attach_enabled);

		plugin_searchsqlite_exec($db, 'COMMIT');
	} catch (Throwable $e) {
		@$db->exec('ROLLBACK');
		flock($lock_fp, LOCK_UN);
		fclose($lock_fp);
		throw $e; // 上位で標準検索へフォールバック
	}

	flock($lock_fp, LOCK_UN);
	fclose($lock_fp);
}

/**
 * attach/ を走査し、現物添付ファイルの件数と最大mtimeを返す (DBには触れない)。
 * 鮮度検査で添付の追加/削除/更新を検出するために使う。
 *
 * @return array('count' => int, 'max_mtime' => int)
 */
function plugin_searchsqlite_scan_attachments()
{
	$result = array('count' => 0, 'max_mtime' => 0);
	if (! defined('UPLOAD_DIR') || ! is_dir(UPLOAD_DIR)) return $result;
	$dp = @opendir(UPLOAD_DIR);
	if ($dp === FALSE) return $result;
	while (($file = readdir($dp)) !== FALSE) {
		// 現物のみ: HEX_HEX (末尾 .N バックアップや .log を除外)
		if (! preg_match('/^[0-9A-Fa-f]+_[0-9A-Fa-f]+$/', $file)) continue;
		$result['count']++;
		$mt = @filemtime(UPLOAD_DIR . $file);
		if ($mt !== FALSE && $mt > $result['max_mtime']) $result['max_mtime'] = $mt;
	}
	closedir($dp);
	return $result;
}

/**
 * attach/ を走査し添付ファイル名を attachments に登録。
 *
 * 添付ファイルは UPLOAD_DIR に `HEXページ名_HEXファイル名` 形式で保存される。
 * 末尾に .N (世代バックアップ) や .log が付くものは現物ではないので除外する。
 * 取得に失敗しても例外を投げず 0 件で返す (本文検索は継続する)。
 *
 * @return array('count' => int, 'max_mtime' => int)
 */
function plugin_searchsqlite_index_attachments($db)
{
	$ret = array('count' => 0, 'max_mtime' => 0);
	if (! defined('UPLOAD_DIR') || ! is_dir(UPLOAD_DIR)) return $ret;
	$dp = @opendir(UPLOAD_DIR);
	if ($dp === FALSE) return $ret;

	$ins = $db->prepare('INSERT OR REPLACE INTO attachments(page, filename, mtime, size) VALUES(:p, :f, :m, :s)');
	if ($ins === FALSE) { closedir($dp); return $ret; }
	while (($file = readdir($dp)) !== FALSE) {
		// 現物のみ: HEX_HEX (末尾拡張子なし)
		if (! preg_match('/^([0-9A-Fa-f]+)_([0-9A-Fa-f]+)$/', $file, $m)) continue;
		$page = pkwk_hex2bin($m[1]);
		$name = pkwk_hex2bin($m[2]);
		if ($page === '' || $name === '') continue;
		$path = UPLOAD_DIR . $file;
		$mt = @filemtime($path);
		if ($mt !== FALSE && $mt > $ret['max_mtime']) $ret['max_mtime'] = $mt;
		$ins->bindValue(':p', $page, SQLITE3_TEXT);
		$ins->bindValue(':f', $name, SQLITE3_TEXT);
		$ins->bindValue(':m', $mt, SQLITE3_INTEGER);
		$ins->bindValue(':s', @filesize($path), SQLITE3_INTEGER);
		$ins->execute();
		$ins->reset();
		$ret['count']++;
	}
	closedir($dp);
	return $ret;
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
	// query() が FALSE を返したら (DB破損等) 例外を投げ、上位で標準検索へ戻す
	$bodies = array(); // page => body
	$res = $db->query('SELECT page, body FROM pages');
	if ($res === FALSE) throw new Exception('query(pages) failed');
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
	// 添付名取得の失敗は致命的ではない (追加仕様)。prepare/execute いずれの失敗でも
	// 空配列を返し、本文・ページ名の検索結果はそのまま返す (検索全体は止めない)。
	$stmt = $db->prepare('SELECT filename FROM attachments WHERE page = :p');
	if ($stmt === FALSE) return array();
	$stmt->bindValue(':p', $page, SQLITE3_TEXT);
	$res = $stmt->execute();
	if ($res === FALSE) { $stmt->close(); return array(); }
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
