# -*- coding: utf-8 -*-
"""Kurage 学童保育ナビ（kgakudo）:18382

住所を入れると、その自治体の放課後児童クラブ（学童保育）のクラブ数・登録児童数・待機児童数を返す。
出どころはこども家庭庁の全国調査。**国が出していない粒度の数字は作らない。**

なぜ要るか: 国の公表はPDFだけで e-Stat にも載っておらず、市区町村名で出ているのは
待機児童が50人以上いる88市町村だけ。全国16,330人のうち5,122人（31.4%）は、
どの市町村のものか国の公表からは分からない（2026-09-21 実測）。
"""
import os
import urllib.parse
from datetime import date

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import HTMLResponse, JSONResponse, PlainTextResponse, FileResponse
from fastapi.templating import Jinja2Templates

from app import lookup

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.environ.get('KGAKUDO_SITE_NAME', 'Kurage 学童保育ナビ')
PUBLIC_BASE = os.environ.get('KGAKUDO_PUBLIC_BASE', 'https://kurage.exbridge.jp/kgakudo.php')
YEAR = int(os.environ.get('KGAKUDO_YEAR', '2025'))

app = FastAPI(title=SITE)
tpl = Jinja2Templates(directory=os.path.join(ROOT, 'app', 'templates'))
LINKS = dict(buy='https://kappstore.exbridge.jp/', komon='https://exbridge.jp/outsourcing/',
             kflood='https://kurage.exbridge.jp/kflood.php/')


def page(request: Request, name: str, **kw):
    kw.update(site=SITE, links=LINKS, year=date.today().year, nat=lookup.national(YEAR),
              data_year=YEAR, base=PUBLIC_BASE)
    return tpl.TemplateResponse(request, name, kw)


@app.get('/', response_class=HTMLResponse)
def index(request: Request, q: str = ''):
    if not q:
        pref = [r for r in lookup.data(YEAR)['areas'] if r['kind'] == '都道府県']
        return page(request, 'index.html', nav='home', areas_pref=pref)
    res = lookup.judge(_resolve(q), YEAR)
    return page(request, 'result.html', nav='home', res=res, q=q)


def _resolve(q: str) -> str:
    """住所文字列を国土地理院の検索で正規化する。見つからなければ入力をそのまま使う。"""
    try:
        g = lookup.geocode(q)
        return (g or {}).get('title') or q
    except Exception:  # noqa: BLE001
        return q


@app.get('/api/check')
def api_check(q: str = ''):
    if not q:
        raise HTTPException(400, '住所を指定してください')
    return JSONResponse(lookup.judge(_resolve(q), YEAR), headers={'Cache-Control': 'no-store'})


@app.get('/area/{name}', response_class=HTMLResponse)
def area_page(request: Request, name: str):
    a = lookup.area_by_name(YEAR, name)
    if not a:
        raise HTTPException(404, 'その自治体のページはありません')
    d = lookup.data(YEAR)
    cities = [r for r in d['waiting50'] if r['pref'] == name] if a['kind'] == '都道府県' else []
    return page(request, 'area.html', nav='area', a=a, cities=cities)


@app.get('/waiting50', response_class=HTMLResponse)
def waiting50(request: Request):
    return page(request, 'waiting50.html', nav='waiting50', rows=lookup.data(YEAR)['waiting50'])


@app.get('/data', response_class=HTMLResponse)
def data_page(request: Request):
    d = lookup.data(YEAR)
    return page(request, 'data.html', nav='data', areas=d['areas'], years=lookup.years())


@app.get('/data/{fname}')
def data_file(fname: str):
    if not fname.startswith('gakudo_') or not fname.endswith(('.csv', '.json')):
        raise HTTPException(404, 'そのファイルはありません')
    p = os.path.join(ROOT, 'data', fname)
    if not os.path.exists(p):
        raise HTTPException(404, 'そのファイルはありません')
    return FileResponse(p, filename=fname,
                        media_type='text/csv; charset=utf-8' if fname.endswith('.csv') else 'application/json')


@app.get('/about', response_class=HTMLResponse)
def about(request: Request):
    return page(request, 'about.html', nav='about')


@app.get('/healthz')
def healthz():
    n = lookup.national(YEAR)
    return dict(ok=True, year=YEAR, waiting=n['waiting'], areas=len(lookup.data(YEAR)['areas']))


@app.get('/sitemap.xml')
def sitemap():
    d = lookup.data(YEAR)
    urls = ['', 'waiting50', 'data', 'about']
    urls += [f"area/{urllib.parse.quote(r['name'])}" for r in d['areas']]
    body = ('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            + ''.join(f'<url><loc>{PUBLIC_BASE}/{u}</loc></url>' for u in urls) + '</urlset>')
    return PlainTextResponse(body, media_type='application/xml')


@app.get('/robots.txt', response_class=PlainTextResponse)
def robots():
    return f"User-agent: *\nAllow: /\nSitemap: {PUBLIC_BASE}/sitemap.xml\n"


@app.get('/llms.txt', response_class=PlainTextResponse)
def llms():
    n = lookup.national(YEAR)
    return (f"# {SITE}\n\n"
            f"住所を入れると、その自治体の放課後児童クラブ（学童保育）のクラブ数・登録児童数・待機児童数を返します。\n"
            f"出典: こども家庭庁「放課後児童健全育成事業（放課後児童クラブ）の実施状況」（{n['as_of']}現在）。\n\n"
            f"- 全国の待機児童 {n['waiting']:,}人／クラブ {n['clubs']:,}か所／登録児童 {n['registered']:,}人\n"
            f"- 国が市区町村名で公表しているのは、待機児童が50人以上の{n['waiting50_count']}市町村だけ\n"
            f"- 残る{n['unnamed']:,}人（{n['unnamed_pct']}%）は、どの市町村のものか国の公表では分かりません\n"
            f"- 当社は推定をしません。公表されていない市町村は「未公表」と書きます\n\n"
            f"住所で調べる: {PUBLIC_BASE}/\nデータ配布(CSV/JSON): {PUBLIC_BASE}/data\n")
