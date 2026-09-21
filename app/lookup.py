# -*- coding: utf-8 -*-
"""住所 → その自治体の学童保育（放課後児童クラブ）の数字。

**国が出していない粒度は、作らない。** こども家庭庁の公表は
  ・都道府県47（指定都市・中核市等を除いた県内の合計）
  ・指定都市・中核市等82
  ・待機児童が50人以上いる市町村88
の3つだけ。これ以外の市町村は**未公表**と書く（推定しない）。
"""
import json
import os
import re

import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GSI = 'https://msearch.gsi.go.jp/address-search/AddressSearch'
UA = {'User-Agent': 'kgakudo/1.0 (kurage.exbridge.jp; +https://exbridge.jp/)'}
PREF = re.compile(r'^(北海道|東京都|京都府|大阪府|.{2,3}?県)')

_data = {}


def data(year: int = 2025) -> dict:
    if year not in _data:
        with open(os.path.join(ROOT, 'data', f'gakudo_{year}.json'), encoding='utf-8') as f:
            _data[year] = json.load(f)
    return _data[year]


def years() -> list:
    out = []
    for f in os.listdir(os.path.join(ROOT, 'data')):
        m = re.match(r'gakudo_(\d{4})\.json$', f)
        if m:
            out.append(int(m.group(1)))
    return sorted(out, reverse=True)


def geocode(q: str):
    r = requests.get(GSI, params={'q': q}, timeout=10, headers=UA)
    r.raise_for_status()
    items = r.json()
    if not items:
        return None

    def score(it):
        t = it.get('properties', {}).get('title', '')
        return (q in t, t.startswith(q), -len(t))
    it = max(items, key=score)
    lon, lat = it['geometry']['coordinates']
    return dict(lon=float(lon), lat=float(lat), title=it['properties'].get('title', ''))


def split_address(addr: str):
    """住所を 都道府県 と 市区町村 に分ける。

    政令市は区ではなく市で数字が出ているので「名古屋市瑞穂区」→「名古屋市」にする。
    郡部は「海部郡蟹江町」→「蟹江町」。東京23区は「千代田区」がそのまま市区町村。
    """
    if not addr:
        return None, None
    m = PREF.match(addr)
    if not m:
        return None, None
    pref = m.group(1)
    rest = addr[len(pref):]
    m2 = re.match(r'(.+?市)', rest)
    if m2:
        return pref, m2.group(1)
    m3 = re.match(r'(?:.+?郡)?(.+?[町村])', rest)
    if m3:
        return pref, m3.group(1)
    m4 = re.match(r'(.+?区)', rest)
    if m4:
        return pref, m4.group(1)
    return pref, None


def area_by_name(year: int, name: str):
    for r in data(year)['areas']:
        if r['name'] == name:
            return r
    return None


def waiting50_of(year: int, pref: str, city: str):
    for r in data(year)['waiting50']:
        if r['pref'] == pref and r['city'] == city:
            return r
    return None


def judge(addr: str, year: int = 2025) -> dict:
    """住所ひとつぶんの答え。status で「何が分かって、何が分からないか」を出し分ける。

    city      … その市区町村が指定都市・中核市等で、数字がそのまま出ている
    listed    … 待機50人以上の一覧に載っている（待機の人数だけ分かる）
    unpublished … 国が市区町村別を公表していない（都道府県の数字だけ添える）
    """
    d = data(year)
    pref, city = split_address(addr)
    out = dict(year=year, as_of=d['as_of'], address=addr, pref=pref, city=city,
               source=d['source'], source_url=d['source_url'], status='unknown',
               area=None, pref_area=None, listed=None)
    if not pref:
        return out
    out['pref_area'] = area_by_name(year, pref)
    if city:
        a = area_by_name(year, city)
        if a and a['kind'] != '都道府県':
            out.update(status='city', area=a)
            return out
        w = waiting50_of(year, pref, city)
        if w:
            out.update(status='listed', listed=w)
            return out
        out['status'] = 'unpublished'
        return out
    out['status'] = 'unpublished'
    return out


def national(year: int = 2025) -> dict:
    """全国の数字と、「市区町村名で分かるのはどこまでか」。"""
    d = data(year)
    a = d['areas']
    w50 = d['waiting50']
    total = sum(r.get('waiting', 0) for r in a)
    named = sum(r['waiting'] for r in w50)
    return dict(year=year, as_of=d['as_of'], clubs=sum(r['clubs'] for r in a),
                registered=sum(r['registered'] for r in a), waiting=total,
                waiting50_count=len(w50), waiting50_sum=named,
                unnamed=total - named, unnamed_pct=round((total - named) * 100 / total, 1) if total else 0)
