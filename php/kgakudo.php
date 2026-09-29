<?php
/**
 * Kurage 学童保育ナビ（kgakudo）— PHP 1枚で動く。
 *
 * 住所を入れると、その自治体の放課後児童クラブ（学童保育）のクラブ数・登録児童数・待機児童数を返す。
 * 出どころはこども家庭庁の全国調査。**国が出していない粒度の数字は作らない。**
 *
 * なぜPHP1枚か:
 *   判定に使うデータは JSON で約29KB（129自治体＋88市町村）。年1回しか変わらない。
 *   計算は「住所を都道府県と市区町村に割って JSON を引く」だけで、地図も空間検索も要らない。
 *   だからバックエンドのサーバーもポートも要らない。置くだけで動き、置いた人のサーバーで完結する。
 *   （PDF→JSON の取り込みだけ Python。年1回、手元で回して JSON を差し替える）
 *
 * 置き方:
 *   kgakudo.php               … このファイル
 *   kgakudo_data/gakudo_2025.json          … データ（scripts/parse_cfa.py が作る）
 *   kgakudo_data/gakudo_areas_2025.csv     … 配布用
 *   kgakudo_data/gakudo_waiting50_2025.csv … 配布用
 *
 * heteml に置くときは、その階層の .htaccess に `AddHandler php-script .php` が要る（既定はPHP5.6）。
 */

// ── 設定 ───────────────────────────────────────────────
$YEAR = 2025;
$SITE = 'Kurage 学童保育ナビ';
$DATA_DIR = __DIR__ . '/kgakudo_data';
$SELF = strtok($_SERVER['SCRIPT_NAME'], '?');           // 例: /kgakudo.php
$GSI = 'https://msearch.gsi.go.jp/address-search/AddressSearch';

$D = json_decode(@file_get_contents("$DATA_DIR/gakudo_$YEAR.json"), true);
if (!$D) { http_response_code(500); echo 'データを読み込めませんでした'; exit; }
// 保育所（保育園）。こども家庭庁が Excel で1,741市区町村ぶん出しているので、住所から引ける形にして添える。
$HK = json_decode(@file_get_contents("$DATA_DIR/hoiku_$YEAR.json"), true);

// ── データの引き当て ────────────────────────────────────
function area_by_name($D, $name) {
    foreach ($D['areas'] as $r) { if ($r['name'] === $name) return $r; }
    return null;
}
function waiting50_of($D, $pref, $city) {
    foreach ($D['waiting50'] as $r) { if ($r['pref'] === $pref && $r['city'] === $city) return $r; }
    return null;
}
/** 保育所（保育園）の数字。無い市区町村は null を返す（0と断定しない）。 */
function hoiku_of($HK, $pref, $city) {
    if (!$HK || !$pref || !$city) return null;
    foreach ($HK['municipalities'] as $r) {
        if ($r['pref'] === $pref && $r['city'] === $city) return $r;
    }
    return null;
}

function hoiku_national($HK) {
    if (!$HK) return null;
    $w = 0; $n = 0; $cap = 0; $app = 0;
    foreach ($HK['municipalities'] as $r) {
        if (isset($r['waiting']) && $r['waiting'] !== null) { $w += (int)$r['waiting']; if ((int)$r['waiting'] > 0) $n++; }
        $cap += (int)(isset($r['capacity_hoikusho']) ? $r['capacity_hoikusho'] : 0);
        $app += (int)(isset($r['applicants']) ? $r['applicants'] : 0);
    }
    return array('waiting' => $w, 'cities' => $n, 'capacity' => $cap, 'applicants' => $app,
                 'as_of' => $HK['as_of'], 'total' => count($HK['municipalities']));
}

function national($D) {
    $w = 0; $c = 0; $g = 0;
    foreach ($D['areas'] as $r) { $w += isset($r['waiting']) ? $r['waiting'] : 0; $c += $r['clubs']; $g += $r['registered']; }
    $named = 0;
    foreach ($D['waiting50'] as $r) { $named += $r['waiting']; }
    return array('clubs' => $c, 'registered' => $g, 'waiting' => $w, 'as_of' => $D['as_of'],
        'w50n' => count($D['waiting50']), 'w50sum' => $named, 'unnamed' => $w - $named,
        'pct' => $w ? round(($w - $named) * 100 / $w, 1) : 0);
}

/** 住所を 都道府県 と 市区町村 に割る。政令市は区ではなく市（名古屋市瑞穂区→名古屋市）。 */
function split_address($addr) {
    if (!$addr) return array(null, null);
    if (!preg_match('/^(北海道|東京都|京都府|大阪府|.{2,3}?県)/u', $addr, $m)) return array(null, null);
    $pref = $m[1];
    $rest = mb_substr($addr, mb_strlen($pref, 'UTF-8'), null, 'UTF-8');
    if (preg_match('/^(.+?市)/u', $rest, $m2)) return array($pref, $m2[1]);
    if (preg_match('/^(?:.+?郡)?(.+?[町村])/u', $rest, $m3)) return array($pref, $m3[1]);
    if (preg_match('/^(.+?区)/u', $rest, $m4)) return array($pref, $m4[1]);
    return array($pref, null);
}

/** 国土地理院の住所検索で表記をそろえる。落ちたら入力をそのまま使う（判定は続ける）。 */
function normalize_address($q, $GSI) {
    $url = $GSI . '?q=' . rawurlencode($q);
    $ctx = stream_context_create(array('http' => array('timeout' => 6, 'header' => "User-Agent: kgakudo/1.0\r\n")));
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return $q;
    $items = json_decode($raw, true);
    if (!is_array($items) || !count($items)) return $q;
    $best = null; $bestScore = -1;
    foreach ($items as $it) {
        $t = isset($it['properties']['title']) ? $it['properties']['title'] : '';
        $score = (mb_strpos($t, $q) !== false ? 2 : 0) + (mb_strpos($t, $q) === 0 ? 1 : 0);
        if ($score > $bestScore) { $bestScore = $score; $best = $t; }
    }
    return $best ? $best : $q;
}

function judge($D, $addr, $HK = null) {
    list($pref, $city) = split_address($addr);
    $out = array('address' => $addr, 'pref' => $pref, 'city' => $city, 'status' => 'unknown',
                 'area' => null, 'pref_area' => null, 'listed' => null, 'as_of' => $D['as_of'],
                 'source' => $D['source'], 'source_url' => $D['source_url'], 'hoiku' => null);
    if (!$pref) return $out;
    $out['hoiku'] = hoiku_of($HK, $pref, $city);
    $out['pref_area'] = area_by_name($D, $pref);
    if ($city) {
        $a = area_by_name($D, $city);
        if ($a && $a['kind'] !== '都道府県') { $out['status'] = 'city'; $out['area'] = $a; return $out; }
        $w = waiting50_of($D, $pref, $city);
        if ($w) { $out['status'] = 'listed'; $out['listed'] = $w; return $out; }
    }
    $out['status'] = 'unpublished';
    return $out;
}

// ── 画面の部品 ─────────────────────────────────────────
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((int)$v); }

function head_html($title, $desc, $SELF, $SITE, $nav, $canon) {
    $base = 'https://kurage.exbridge.jp' . $SELF;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($base . $canon) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:type" content="website">';
    // OGP画像・Xカード。AI検索（AEO/GEO）にも効くので、どのページでも必ず出す。
    echo '<meta property="og:image" content="https://kurage.exbridge.jp/kgakudo_data/ogp.png">';
    echo '<meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($base . $canon) . '">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="https://kurage.exbridge.jp/kgakudo_data/ogp.png">';
    echo '<style>'
       . ':root{--ink:#12202f;--mut:#5d6b7a;--teal:#0a9a8f;--teal-d:#087f76;--line:#dfe7ec;--bg:#f5f8fa;--red-l:#fdecea;--amber-l:#fdf6e3;--blue:#2c6fbb;--blue-l:#eaf2fb}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.8 "Noto Sans JP",system-ui,sans-serif}'
       . 'a{color:var(--teal-d)}.wrap{width:min(940px,100% - 32px);margin:0 auto}'
       . 'header{background:#fff;border-bottom:1px solid var(--line)}.brand{display:block;padding:14px 0 6px;font-weight:800;font-size:18px;text-decoration:none;color:var(--ink)}'
       . '.menu{display:flex;gap:14px;flex-wrap:wrap;padding-bottom:12px;font-size:14px}.menu a{text-decoration:none;color:var(--mut)}.menu a.on{color:var(--teal-d);font-weight:700}'
       . 'main{padding:22px 0 40px}h1{font-size:26px;line-height:1.4;margin:0 0 10px}h2{font-size:20px;margin:26px 0 10px}.lead{color:var(--mut)}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin:14px 0}'
       . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}'
       . '.card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;min-width:0}'
       . '.card.none{background:#fbfcfd}.card.lv2{border-color:#e6c98b;background:var(--amber-l)}.card.lv3{border-color:#e3a9a1;background:var(--red-l)}'
       . '.card .k{font-size:12px;color:var(--mut)}.card .v{font-size:22px;font-weight:800;margin-top:4px}.card .s{font-size:12px;color:var(--mut);margin-top:4px}'
       . '.pol{border:2px solid var(--line);border-radius:14px;padding:16px;font-weight:800;font-size:18px;background:#fff}'
       . '.pol small{display:block;font-weight:400;font-size:14px;color:var(--mut);margin-top:6px;line-height:1.8}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.8}'
       . '.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
       . '.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}'
       . 'input[type=text]{flex:1 1 260px;min-width:0;font-size:17px;padding:12px 14px;border:2px solid var(--line);border-radius:10px}'
       . '.tscroll{overflow-x:auto}table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:420px}'
       . 'table.t th,table.t td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}'
       . 'table.t th{color:var(--mut);font-size:12px}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . 'footer{border-top:1px solid var(--line);padding:22px 0 40px;color:var(--mut);font-size:13px;background:#fff}ul.plain{margin:0;padding-left:20px}'
       . '</style>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script>';
    // 構造化データ。AI検索（AEO/GEO）は「何のデータを、どこから取り、どこまで分かるか」を読む。
    global $D, $NATG;
    $ld = array(
        '@context' => 'https://schema.org',
        '@graph' => array(
            array('@type' => 'WebApplication', 'name' => $SITE,
                  'url' => $base . '/', 'applicationCategory' => 'GovernmentApplication',
                  'operatingSystem' => 'Web', 'inLanguage' => 'ja',
                  'description' => '住所を入れると、その自治体の放課後児童クラブ（学童保育）のクラブ数・登録児童数・待機児童数を返します。',
                  'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'),
                  'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
            array('@type' => 'Dataset', 'name' => '放課後児童クラブ（学童保育）の実施状況 ' . $D['as_of'],
                  'description' => 'こども家庭庁の全国調査から、都道府県47・指定都市/中核市等82・待機児童50人以上の市町村88件を機械で読める形にしたもの。推定値は含みません。',
                  'url' => $base . '/data', 'inLanguage' => 'ja',
                  'temporalCoverage' => $D['as_of'],
                  'creator' => array('@type' => 'GovernmentOrganization', 'name' => 'こども家庭庁'),
                  'isBasedOn' => $D['source_url'],
                  'license' => 'https://www.digital.go.jp/resources/open_data',
                  'distribution' => array(
                      array('@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $base . '/data/gakudo_areas_2025.csv'),
                      array('@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => $base . '/data/gakudo_2025.json'))),
            array('@type' => 'Dataset', 'name' => '保育所等関連状況（定員・申込者・待機児童）',
                  'description' => 'こども家庭庁「保育所等関連状況取りまとめ」から、全1,741市区町村の定員・申込者と、待機児童がいる市区町村の待機児童数を住所から引ける形にしたもの。',
                  'url' => $base . '/data', 'inLanguage' => 'ja',
                  'creator' => array('@type' => 'GovernmentOrganization', 'name' => 'こども家庭庁'),
                  'isBasedOn' => 'https://www.cfa.go.jp/policies/hoiku/torimatome/'),
            array('@type' => 'FAQPage', 'mainEntity' => array(
                array('@type' => 'Question', 'name' => '保育園の待機児童数も住所から調べられますか',
                      'acceptedAnswer' => array('@type' => 'Answer', 'text' => '調べられます。保育所（保育園）はこども家庭庁がExcelで全1,741市区町村ぶんを公表しているため、定員と申込者は全国どこでも出ます。待機児童数は「待機児童がいる市区町村」の一覧にある分だけで、一覧に無い市区町村は0人とは書かずに「一覧に載っていません」と表示します。')),
                array('@type' => 'Question', 'name' => '学童保育の待機児童数は、自分の市の数字を調べられますか',
                      'acceptedAnswer' => array('@type' => 'Answer', 'text' => '指定都市・中核市など82自治体と、待機児童が50人以上いる88市町村については分かります。それ以外の市町村は国が公表していないため、このサイトでは「未公表」と表示します。待機児童がいないという意味ではありません。')),
                array('@type' => 'Question', 'name' => '全国の学童保育の待機児童は何人ですか',
                      'acceptedAnswer' => array('@type' => 'Answer', 'text' => $NATG['waiting'] . '人です（' . $D['as_of'] . '現在・こども家庭庁調査）。放課後児童クラブは' . $NATG['clubs'] . 'か所、登録児童数は' . $NATG['registered'] . '人です。')),
                array('@type' => 'Question', 'name' => 'なぜ市区町村別の数字が分からないのですか',
                      'acceptedAnswer' => array('@type' => 'Answer', 'text' => '国の公表がPDFのみで、市区町村名で数字が出ているのは待機児童が50人以上いる' . $NATG['w50n'] . '市町村だけだからです。全国' . $NATG['waiting'] . '人のうち' . $NATG['unnamed'] . '人（' . $NATG['pct'] . '%）は、どの市町村のものか国の公表資料からは分かりません。調査自体は市区町村ごとに行われています。')),
                array('@type' => 'Question', 'name' => 'データはどこから取っていますか',
                      'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'こども家庭庁「放課後児童健全育成事業（放課後児童クラブ）の実施状況」です。PDFから表を取り出し、合計が国の公表する全国値と一致することを確認しています。推定した数字は含みません。')))),
        ));
    echo '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    // 再販パートナー募集の枠（中身は kurage_web/partner-bar.js）。当社の公開先でだけ読む（配布版を置いたサイトからは当社へ通信しない）
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    echo '</head><body><header><div class="wrap"><a class="brand" href="' . h($SELF) . '/">' . h($SITE) . '</a><nav class="menu">';
    $items = array('' => '住所で調べる', 'waiting50' => '待機が多い市町村', 'data' => 'データ配布', 'about' => 'このデータについて');
    foreach ($items as $k => $label) {
        echo '<a href="' . h($SELF) . '/' . $k . '" class="' . ($nav === $k ? 'on' : '') . '">' . h($label) . '</a>';
    }
    echo '</nav></div></header><main><div class="wrap">';
}

function foot_html($D, $SELF) {
    echo '</div></main><footer><div class="wrap">';
    echo '<div>出典: ' . h($D['source']) . '（' . h($D['as_of']) . '現在）を加工して作成。当社は国が公表していない数字を推定しません。</div>';
    echo '<div style="margin-top:6px">関連: <a href="https://kurage.exbridge.jp/kflood.php/">洪水・内水ハザードマップ</a>／<a href="https://exbridge.jp/outsourcing/" target="_blank" rel="noopener">AI-IT顧問契約</a></div>';
    echo '<div style="margin-top:6px"><a href="https://kappstore.exbridge.jp/app.php?id=f2853d368ddf8e57&amp;ref=kgakudo" rel="noopener">このサイトの一式をオンプレミスで導入する（商品ページ）</a></div>';
    echo '</div></footer></body></html>';
}

function search_form($SELF, $value, $label) {
    echo '<div class="panel"><form action="' . h($SELF) . '/" method="get" style="display:flex;gap:8px;flex-wrap:wrap">';
    echo '<input type="text" name="q" value="' . h($value) . '" placeholder="例: 愛知県名古屋市瑞穂区内浜町" required>';
    echo '<button class="btn" type="submit">' . h($label) . '</button></form></div>';
}

// ── ルーティング ───────────────────────────────────────
$path = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$nat = national($D);
$GLOBALS['NATG'] = $nat;
$HKN = hoiku_national($HK);
$GLOBALS['HKNG'] = $HKN;

// データファイルの配布
if (strpos($path, 'data/') === 0) {
    $f = basename(substr($path, 5));
    if (preg_match('/^(gakudo|hoiku)_[a-z0-9_]+\.(csv|json)$/', $f) && is_file("$DATA_DIR/$f")) {
        header('Content-Type: ' . (substr($f, -4) === '.csv' ? 'text/csv; charset=utf-8' : 'application/json'));
        header('Content-Disposition: attachment; filename="' . $f . '"');
        readfile("$DATA_DIR/$f"); exit;
    }
    http_response_code(404); echo 'そのファイルはありません'; exit;
}
// 住所ひとつぶんのJSON
if ($path === 'api/check') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(judge($D, $q ? normalize_address($q, $GSI) : '', $HK), JSON_UNESCAPED_UNICODE); exit;
}
if ($path === 'llms.txt' || $path === 'robots.txt' || $path === 'sitemap.xml') {
    $base = 'https://kurage.exbridge.jp' . $SELF;
    if ($path === 'robots.txt') { header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nAllow: /\nSitemap: $base/sitemap.xml\n"; exit; }
    if ($path === 'sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        // lastmod は**データの時点**。毎回 now を入れない（いつも更新されていることになって無視される）。
        // 2026-09-22 実測: サイトマップ索引29本のうち Google が取得していたのは lastmod のある3本だけ。
        $LASTMOD = preg_match('/(\d{4})[^\d]*(\d{1,2})/u', (string)($D['as_of'] ?? ''), $mm)
                 ? sprintf('%04d-%02d-01', $mm[1], $mm[2]) : gmdate('Y-m-d');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array('', 'waiting50', 'data', 'about') as $u) { echo '<url><loc>' . h("$base/$u") . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>'; }
        foreach ($D['areas'] as $r) { echo '<url><loc>' . h("$base/area/" . rawurlencode($r['name'])) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>'; }
        echo '</urlset>'; exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "# $SITE\n\n住所を入れると、その自治体の放課後児童クラブ（学童保育）のクラブ数・登録児童数・待機児童数を返します。\n";
    echo "出典: {$D['source']}（{$D['as_of']}現在）。\n\n";
    echo "- 全国の待機児童 " . n($nat['waiting']) . "人／クラブ " . n($nat['clubs']) . "か所／登録児童 " . n($nat['registered']) . "人\n";
    echo "- 国が市区町村名で公表しているのは、待機児童が50人以上の{$nat['w50n']}市町村だけ\n";
    echo "- 残る " . n($nat['unnamed']) . "人（{$nat['pct']}%）は、どの市町村のものか国の公表では分かりません\n";
    echo "- 当社は推定をしません。公表されていない市町村は「未公表」と書きます\n\n";
    if ($HK) {
        echo "\n## 保育所（保育園）も同じ住所で出ます\n";
        echo "- 出典: こども家庭庁「保育所等関連状況取りまとめ」（{$HK['as_of']}現在）。こちらは国がExcelで全1,741市区町村ぶんを公表しています\n";
        echo "- 全国の待機児童 " . n($HKN['waiting']) . "人・" . n($HKN['cities']) . "市区町村\n";
        echo "- 待機児童の一覧に無い市区町村は「一覧に載っていません」と書き、0人とは書きません\n";
    }
    echo "\n住所で調べる: $base/\nデータ配布(CSV/JSON): $base/data\n"; exit;
}

// 都道府県・市のページ
if (strpos($path, 'area/') === 0) {
    $name = rawurldecode(substr($path, 5));
    $a = area_by_name($D, $name);
    if (!$a) { http_response_code(404); head_html('ページがありません｜' . $SITE, '', $SELF, $SITE, '', '/'); echo '<h1>そのページはありません</h1>'; foot_html($D, $SELF); exit; }
    $title = $a['name'] . 'の学童保育｜待機児童' . n($a['waiting']) . '人・クラブ' . n($a['clubs']) . 'か所';
    $desc = $a['name'] . 'の放課後児童クラブ（学童保育）は' . n($a['clubs']) . 'か所、登録児童' . n($a['registered']) . '人、待機児童' . n($a['waiting']) . '人（' . $D['as_of'] . '現在・こども家庭庁調査）。';
    head_html($title, $desc, $SELF, $SITE, '', '/area/' . rawurlencode($a['name']));
    echo '<h1>' . h($a['name']) . 'の学童保育（放課後児童クラブ）</h1>';
    echo '<p class="lead">' . h($D['as_of']) . '現在の数字です。' . ($a['kind'] === '都道府県'
        ? '県内の指定都市・中核市等は、国の資料が別に集計しているため、この数字には含まれていません。'
        : h($a['name']) . 'は指定都市・中核市等なので、市単位の数字が国の資料に出ています。') . '</p>';
    $cls = $a['waiting'] >= 100 ? 'lv3' : ($a['waiting'] > 0 ? 'lv2' : 'none');
    echo '<div class="grid">'
       . '<div class="card"><div class="k">放課後児童クラブ</div><div class="v">' . n($a['clubs']) . 'か所</div></div>'
       . '<div class="card"><div class="k">登録児童数</div><div class="v">' . n($a['registered']) . '人</div></div>'
       . '<div class="card ' . $cls . '"><div class="k">待機児童</div><div class="v">' . n($a['waiting']) . '人</div><div class="s">前年 ' . n($a['waiting_prev']) . '人</div></div>'
       . '</div>';
    if ($a['kind'] === '都道府県') {
        $cities = array();
        foreach ($D['waiting50'] as $r) { if ($r['pref'] === $a['name']) $cities[] = $r; }
        if ($cities) {
            echo '<h2>' . h($a['name']) . '内で待機児童が50人以上の市町村</h2><div class="tscroll"><table class="t"><tr><th>市区町村</th><th class="n">待機児童</th><th class="n">全国順位</th></tr>';
            foreach ($cities as $c) { echo '<tr><td>' . h($c['city']) . '</td><td class="n">' . n($c['waiting']) . '人</td><td class="n">' . h($c['no']) . '位</td></tr>'; }
            echo '</table></div><p class="src">50人未満の市町村は、国の資料に名前も数字も出ていません。<b>待機児童がいないという意味ではありません。</b></p>';
        } else {
            echo '<p class="src">' . h($a['name']) . 'には、待機児童が50人以上の市町村はありません。49人以下の市町村の数字は公表されていません。</p>';
        }
    }
    search_form($SELF, '', '住所で調べる');
    foot_html($D, $SELF); exit;
}

if ($path === 'waiting50') {
    head_html('学童保育の待機児童が多い市町村' . $nat['w50n'] . '件（全国一覧）｜' . $SITE,
        '放課後児童クラブ（学童保育）の待機児童が50人以上いる全国' . $nat['w50n'] . '市町村の一覧。こども家庭庁調査（' . $D['as_of'] . '現在）。',
        $SELF, $SITE, 'waiting50', '/waiting50');
    echo '<h1>待機児童が50人以上の市町村（' . $nat['w50n'] . '件）</h1>';
    echo '<p class="lead">国が市区町村名で公表しているのは、この' . $nat['w50n'] . '市町村だけです。合計' . n($nat['w50sum']) . '人で、全国' . n($nat['waiting']) . '人の' . (100 - $nat['pct']) . '%にあたります。<b>残り' . n($nat['unnamed']) . '人がどの市町村のものかは、国の公表では分かりません。</b></p>';
    echo '<div class="tscroll"><table class="t"><tr><th class="n">順位</th><th>都道府県</th><th>市区町村</th><th class="n">待機児童</th></tr>';
    foreach ($D['waiting50'] as $r) {
        echo '<tr><td class="n">' . h($r['no']) . '</td><td><a href="' . h($SELF) . '/area/' . rawurlencode($r['pref']) . '">' . h($r['pref']) . '</a></td><td>' . h($r['city']) . '</td><td class="n">' . n($r['waiting']) . '人</td></tr>';
    }
    echo '</table></div><p class="src">出典: ' . h($D['source']) . '（' . h($D['as_of']) . '現在）。<a href="' . h($SELF) . '/data">CSVで受け取る</a></p>';
    foot_html($D, $SELF); exit;
}

if ($path === 'data') {
    head_html('学童保育の全国データ（CSV・JSON）｜' . $SITE,
        'こども家庭庁の放課後児童クラブ実施状況を、CSVとJSONで配布しています。国の公表はPDFのみでe-Statにも載っていないため、機械で読める形に開きました。',
        $SELF, $SITE, 'data', '/data');
    echo '<h1>データ配布（CSV・JSON）</h1>';
    echo '<p class="lead">こども家庭庁の公表は<b>PDFのみ</b>で、ExcelもCSVもなく、<b>e-Statにも載っていません</b>。そこでPDFから表を取り出し、機械で読める形にしました。合計が国の資料の全国値と一致することを確かめています（待機' . n($nat['waiting']) . '人・クラブ' . n($nat['clubs']) . 'か所・登録' . n($nat['registered']) . '人）。</p>';
    echo '<div class="panel"><h2 style="margin-top:0">' . $GLOBALS['YEAR'] . '年（' . h($D['as_of']) . '現在）</h2><ul class="plain">';
    echo '<li><a href="' . h($SELF) . '/data/gakudo_areas_' . $GLOBALS['YEAR'] . '.csv">都道府県・指定都市・中核市等 ' . count($D['areas']) . '件（CSV）</a> — クラブ数・登録児童数・待機児童数・前年比</li>';
    echo '<li><a href="' . h($SELF) . '/data/gakudo_waiting50_' . $GLOBALS['YEAR'] . '.csv">待機児童50人以上の市町村 ' . $nat['w50n'] . '件（CSV）</a></li>';
    echo '<li><a href="' . h($SELF) . '/data/gakudo_' . $GLOBALS['YEAR'] . '.json">両方まとめて（JSON）</a></li></ul>';
    if ($HK) {
        echo '<h2>保育所（保育園）' . $GLOBALS['YEAR'] . '年（' . h($HK['as_of']) . '現在）</h2><ul class="plain">';
        echo '<li><a href="' . h($SELF) . '/data/hoiku_municipalities_' . $GLOBALS['YEAR'] . '.csv">全1,741市区町村の定員・申込者・待機児童（CSV）</a></li>';
        echo '<li><a href="' . h($SELF) . '/data/hoiku_' . $GLOBALS['YEAR'] . '.json">同じものをJSONで</a></li></ul>';
        echo '<p class="src">こちらは国が Excel で公表しているものを、住所から引ける形に直したものです。待機児童は「待機児童がいる市区町村」の一覧にある分だけで、一覧に無い市区町村は空欄にしてあります（0人と断定しません）。全国の待機児童は' . n($HKN['waiting']) . '人・' . n($HKN['cities']) . '市区町村です。</p>';
    }
    echo '<p class="src">CSVはBOM付きUTF-8なので、Excelでそのまま開けます。二次利用は出典（こども家庭庁）を明記してください。当社の加工は「PDFの表をそのまま写す」ことだけで、推定値は一切足していません。</p></div>';
    echo '<div class="panel"><h2 style="margin-top:0">APIで受け取る</h2><p class="src" style="font-size:14px">住所ひとつぶんの判定は <code>' . h($SELF) . '/api/check?q=住所</code> でJSONが返ります。公表されていない市町村は <code>status: "unpublished"</code> で返し、数字は作りません。</p></div>';
    foot_html($D, $SELF); exit;
}

if ($path === 'about') {
    head_html('このデータについて｜' . $SITE, 'こども家庭庁の放課後児童クラブ実施状況の出どころと、市区町村別が公表されていないことの説明。', $SELF, $SITE, 'about', '/about');
    echo '<h1>このデータについて</h1>';
    echo '<div class="panel"><h2 style="margin-top:0">出どころ</h2><p>' . h($D['source']) . '（' . h($D['as_of']) . '現在）。毎年5月1日時点の全国調査で、令和7年は1,633市区町村が実施しています。</p>';
    echo '<p class="src"><a href="' . h($D['source_url']) . '" target="_blank" rel="noopener">こども家庭庁のページ</a>（PDFはここにあります）</p></div>';
    echo '<div class="panel"><h2 style="margin-top:0">なぜ、このサイトが要るのか</h2><ul class="plain">'
       . '<li>国の公表は<b>PDFだけ</b>です。ExcelもCSVもありません。</li>'
       . '<li><b>e-Statにも載っていません。</b>業務統計のページには「e-Statに掲載されていない」と明記され、データベース・XML・CSV・Excel・PDFの全欄が「なし」になっています。</li>'
       . '<li>表の粒度は<b>都道府県47と指定都市・中核市等82</b>です。市区町村名で出ているのは、<b>待機児童が50人以上いる' . $nat['w50n'] . '市町村</b>だけです。</li>'
       . '<li>全国の待機児童' . n($nat['waiting']) . '人のうち、名前が分かるのは' . n($nat['w50sum']) . '人。<b>残る' . n($nat['unnamed']) . '人（' . $nat['pct'] . '%）がどの市町村のものかは分かりません。</b></li>'
       . '</ul><p>調査は市区町村ごとに行われているのに、公表がここで止まっています。保護者が「自分の市の学童は足りているのか」を国の資料から引けません。市区町村別をCSVで公表し、e-Statに載せてほしい、というのが当社の考えです。</p></div>';
    echo '<div class="panel"><h2 style="margin-top:0">当社がしていること・していないこと</h2><ul class="plain">'
       . '<li>している: PDFの表を機械で読める形に写し、住所から引けるようにする。合計が国の全国値と一致することを確認する。</li>'
       . '<li><b>していない: 公表されていない市町村の数字を推定すること。</b>分からないものは「未公表」と書きます。「待機児童がいない」とは書きません。</li></ul></div>';
    echo '<div class="panel"><h2 style="margin-top:0">数字の読み方</h2><ul class="plain">'
       . '<li>都道府県の数字には、県内の指定都市・中核市等は含まれていません（国の資料が別に集計しているため）。</li>'
       . '<li>「待機児童」は、国の調査では「利用できなかった児童数」です。申込みをして利用できなかった児童を指します。</li>'
       . '<li>クラブの所在地や空き状況は、国のデータにはありません（自治体ごとの公表になります）。</li></ul></div>';
    if ($HK) {
        echo '<div class="panel"><h2 style="margin-top:0">保育所（保育園）の数字について</h2>';
        echo '<p>こども家庭庁「保育所等関連状況取りまとめ」（' . h($HK['as_of']) . '現在）です。<b>こちらは国が Excel で1,741市区町村ぶんを公表しています。</b>学童とは別の調査なので、画面でも枠を分けています。</p>';
        echo '<ul class="plain"><li>定員と申込者は、全1,741市区町村について出ます。</li>';
        echo '<li>待機児童数は「待機児童がいる市区町村」の一覧（' . n($HKN['cities']) . '市区町村・全国' . n($HKN['waiting']) . '人）にある分だけです。一覧に無い市区町村は<b>「一覧に載っていません」</b>と書き、0人とは書きません。</li></ul>';
        echo '<p class="src">学童は公表がPDFだけで市区町村別がほとんど無いのに対し、保育所はExcelで全市区町村ぶんが出ています。同じ役所の、同じ子育ての統計でも、公表の仕方がここまで違います。</p></div>';
    }
    echo '<div class="panel"><h2 style="margin-top:0">この仕組みについて</h2><p class="src">PHP 1ファイルとJSONだけで動きます。データベースも外部のサーバーも使いません。年1回、国が新しい調査結果を出したときにJSONを差し替えれば更新できます。</p></div>';
    foot_html($D, $SELF); exit;
}

// ── トップ（住所入力）と判定結果 ────────────────────────
if ($q !== '') {
    $res = judge($D, normalize_address($q, $GSI), $HK);
    $who = $res['city'] ? $res['city'] : ($res['pref'] ? $res['pref'] : '');
    head_html($who . 'の学童保育の待機児童｜' . $SITE,
        $who . 'の放課後児童クラブ（学童保育）の数字。こども家庭庁の全国調査（' . $D['as_of'] . '現在）から、公表されているものだけを出しています。',
        $SELF, $SITE, '', '/');
    echo '<h1>' . h($who ? $who . 'の学童保育' : '判定できませんでした') . '</h1>';
    echo '<p class="src">住所: <b>' . h($res['address']) . '</b>／' . h($D['as_of']) . '現在</p>';

    if ($res['status'] === 'city') {
        $a = $res['area'];
        $diff = $a['waiting'] - $a['waiting_prev'];
        $word = $diff > 0 ? '（' . n($diff) . '人 増えました）' : ($diff < 0 ? '（' . n(-$diff) . '人 減りました）' : '（前年と同じ）');
        echo '<div class="pol">' . h($a['name']) . 'の待機児童は ' . n($a['waiting']) . '人です<small>前年は' . n($a['waiting_prev']) . '人' . $word . '。' . h($a['name']) . 'は指定都市・中核市等なので、国の資料に市単位の数字が出ています。</small></div>';
        $cls = $a['waiting'] >= 100 ? 'lv3' : ($a['waiting'] > 0 ? 'lv2' : 'none');
        echo '<div class="grid" style="margin-top:12px">'
           . '<div class="card"><div class="k">放課後児童クラブ</div><div class="v">' . n($a['clubs']) . 'か所</div></div>'
           . '<div class="card"><div class="k">登録児童数</div><div class="v">' . n($a['registered']) . '人</div></div>'
           . '<div class="card ' . $cls . '"><div class="k">待機児童</div><div class="v">' . n($a['waiting']) . '人</div><div class="s">前年 ' . n($a['waiting_prev']) . '人</div></div></div>';
    } elseif ($res['status'] === 'listed') {
        $w = $res['listed'];
        echo '<div class="pol">' . h($w['city']) . 'の待機児童は ' . n($w['waiting']) . '人です<small>全国で待機児童が多い順に<b>' . h($w['no']) . '番目</b>です。国が市区町村名で公表しているのは待機児童が50人以上いる市町村だけで、' . h($w['city']) . 'はそこに載っています。<b>クラブ数と登録児童数は、この市町村については公表されていません。</b></small></div>';
        echo '<div class="grid" style="margin-top:12px">'
           . '<div class="card ' . ($w['waiting'] >= 100 ? 'lv3' : 'lv2') . '"><div class="k">待機児童</div><div class="v">' . n($w['waiting']) . '人</div><div class="s">全国' . h($w['no']) . '位</div></div>'
           . '<div class="card none"><div class="k">放課後児童クラブ</div><div class="v">未公表</div><div class="s">国の資料に市町村別がありません</div></div>'
           . '<div class="card none"><div class="k">登録児童数</div><div class="v">未公表</div><div class="s">同上</div></div></div>';
    } elseif ($res['status'] === 'unpublished') {
        echo '<div class="pol" style="border-color:var(--blue);background:var(--blue-l)">' . h($who) . 'の数字は、国が公表していません<small><b>待機児童がいないという意味ではありません。</b>国が市区町村名で公表しているのは、待機児童が50人以上いる' . $nat['w50n'] . '市町村だけです。49人以下の市町村は名前も数字も出ていません。調査自体は市区町村ごとに行われています。</small></div>';
    } else {
        echo '<div class="pol" style="border-color:var(--blue);background:var(--blue-l)">住所から自治体を判定できませんでした<small>都道府県から書いた住所でもう一度お試しください。</small></div>';
    }

    // ── 保育所（保育園）。学童とは別の調査なので、枠を分けて出す ──
    if ($res['hoiku']) {
        $hk = $res['hoiku'];
        echo '<h2>' . h($hk['city']) . 'の保育所（保育園）</h2>';
        if ($hk['waiting'] === null) {
            echo '<div class="pol ok">' . h($hk['city']) . 'は、待機児童がいる市区町村の一覧に載っていません<small>国が公表しているのは「待機児童がいる市区町村」の一覧（211市区町村）です。載っていない市区町村は、その一覧に含まれていないという意味です。</small></div>';
        } else {
            $hw = (int)$hk['waiting'];
            $hp = $hk['waiting_prev'] === null ? null : (int)$hk['waiting_prev'];
            $word = ($hp === null) ? '' : ($hw > $hp ? '（' . n($hw - $hp) . '人 増えました）' : ($hw < $hp ? '（' . n($hp - $hw) . '人 減りました）' : '（前年と同じ）'));
            echo '<div class="pol">' . h($hk['city']) . 'の保育所の待機児童は ' . n($hw) . '人です<small>前年は' . ($hp === null ? '—' : n($hp)) . '人' . $word . '。こども家庭庁「保育所等関連状況取りまとめ」（' . h($HKN['as_of']) . '現在）。</small></div>';
        }
        echo '<div class="grid" style="margin-top:12px">'
           . '<div class="card"><div class="k">保育所の定員</div><div class="v">' . n($hk['capacity_hoikusho']) . '人</div></div>'
           . '<div class="card"><div class="k">認定こども園（幼保連携型）の定員</div><div class="v">' . n(isset($hk['capacity_kodomoen']) ? $hk['capacity_kodomoen'] : 0) . '人</div></div>'
           . '<div class="card"><div class="k">申込者数</div><div class="v">' . n($hk['applicants']) . '人</div></div>'
           . ($hk['waiting'] === null
               ? '<div class="card none"><div class="k">待機児童</div><div class="v">一覧になし</div><div class="s">国の一覧は待機児童がいる市区町村のみ</div></div>'
               : '<div class="card ' . ((int)$hk['waiting'] >= 50 ? 'lv3' : ((int)$hk['waiting'] > 0 ? 'lv2' : 'none')) . '"><div class="k">待機児童</div><div class="v">' . n($hk['waiting']) . '人</div><div class="s">前年 ' . ($hk['waiting_prev'] === null ? '—' : n($hk['waiting_prev'])) . '人</div></div>')
           . '</div>';
        echo '<p class="src" style="margin-top:8px">保育所は学童（放課後児童クラブ）とは別の調査です。こちらは国が1,741市区町村ぶんをExcelで公表しているので、全国どの市区町村でも定員と申込者が出ます。出典: <a href="' . h($HK['source_url']) . '" target="_blank" rel="noopener">' . h($HK['source']) . '</a>（' . h($HK['as_of']) . '現在）</p>';
    }

    if ($res['pref_area']) {
        $p = $res['pref_area'];
        echo '<h2>' . h($p['name']) . '全体（指定都市・中核市等を除く）</h2><div class="grid">'
           . '<div class="card"><div class="k">放課後児童クラブ</div><div class="v">' . n($p['clubs']) . 'か所</div></div>'
           . '<div class="card"><div class="k">登録児童数</div><div class="v">' . n($p['registered']) . '人</div></div>'
           . '<div class="card ' . ($p['waiting'] ? 'lv2' : 'none') . '"><div class="k">待機児童</div><div class="v">' . n($p['waiting']) . '人</div><div class="s">前年 ' . n($p['waiting_prev']) . '人</div></div></div>';
        echo '<p class="src" style="margin-top:8px">この数字には、県内の指定都市・中核市等は含まれていません（国の資料が別に集計しているため）。<a href="' . h($SELF) . '/area/' . rawurlencode($p['name']) . '">' . h($p['name']) . 'のページ</a></p>';
    }
    echo '<div class="panel" style="margin-top:20px"><p style="display:flex;gap:8px;flex-wrap:wrap;margin:0">'
       . '<a class="btn ghost" href="' . h($SELF) . '/">別の住所で調べる</a> '
       . '<a class="btn ghost" href="' . h($SELF) . '/data">CSV・JSONで受け取る</a> '
       . '<a class="btn ghost" href="' . h($SELF) . '/about">このデータについて</a></p></div>';
    foot_html($D, $SELF); exit;
}

head_html('学童保育の待機児童を住所から調べる｜' . $SITE,
    '住所を入れると、その自治体の学童保育（放課後児童クラブ）のクラブ数・登録児童数・待機児童数が分かります。出典はこども家庭庁の全国調査です。',
    $SELF, $SITE, '', '/');
echo '<h1>学童保育の待機児童を、住所から調べる</h1>';
echo '<p class="lead">住所を入れると、その自治体の放課後児童クラブ（学童保育）の<b>クラブ数・登録児童数・待機児童数</b>を返します。数字はこども家庭庁の全国調査（' . h($D['as_of']) . '現在）です。</p>';
search_form($SELF, '', '調べる');
echo '<h2>全国の数字（' . h($D['as_of']) . '現在）</h2><div class="grid">'
   . '<div class="card"><div class="k">放課後児童クラブ</div><div class="v">' . n($nat['clubs']) . 'か所</div></div>'
   . '<div class="card"><div class="k">登録児童数</div><div class="v">' . n($nat['registered']) . '人</div><div class="s">過去最高</div></div>'
   . '<div class="card lv2"><div class="k">利用できなかった児童（待機児童）</div><div class="v">' . n($nat['waiting']) . '人</div></div></div>';
echo '<div class="panel"><div class="pol">待機児童の' . $nat['pct'] . '%は、どの市町村のものか分かりません<small>国が市区町村名で公表しているのは、待機児童が<b>50人以上いる' . $nat['w50n'] . '市町村</b>だけです（合計' . n($nat['w50sum']) . '人）。残る<b>' . n($nat['unnamed']) . '人</b>がどこにいるのかは、国の公表資料からは分かりません。調査自体は市区町村ごとに行われています。</small></div>';
echo '<p class="src" style="margin-top:10px">公表は PDF のみで、Excel も CSV もありません。e-Stat にも載っていません。当社はPDFから表を取り出して <a href="' . h($SELF) . '/data">CSVとJSONで配っています</a>。合計は国の資料の全国値と一致することを確かめています。</p></div>';
if ($HK) {
    echo '<h2>保育所（保育園）も同じ住所で出ます</h2><div class="grid">'
       . '<div class="card"><div class="k">保育所の定員（全国）</div><div class="v">' . n($HKN['capacity']) . '人</div></div>'
       . '<div class="card"><div class="k">申込者数（全国）</div><div class="v">' . n($HKN['applicants']) . '人</div></div>'
       . '<div class="card lv2"><div class="k">待機児童（全国）</div><div class="v">' . n($HKN['waiting']) . '人</div><div class="s">' . n($HKN['cities']) . '市区町村</div></div></div>';
    echo '<p class="src">保育所はこども家庭庁が<b>Excelで全1,741市区町村ぶん</b>を公表しています（' . h($HK['as_of']) . '現在）。学童は同じ役所の調査なのにPDFだけで市区町村別がほとんどありません。公表の仕方が違うと、住民が引けるかどうかも変わります。</p>';
}
echo '<h2>都道府県から見る</h2><div class="panel"><p style="font-size:14px;line-height:2">';
$first = true;
foreach ($D['areas'] as $r) {
    if ($r['kind'] !== '都道府県') continue;
    if (!$first) echo ' ・ ';
    echo '<a href="' . h($SELF) . '/area/' . rawurlencode($r['name']) . '">' . h($r['name']) . '</a>';
    $first = false;
}
echo '</p><p style="margin:10px 0 0"><a class="btn ghost" href="' . h($SELF) . '/waiting50">待機児童が50人以上の' . $nat['w50n'] . '市町村を見る</a></p></div>';
foot_html($D, $SELF);
