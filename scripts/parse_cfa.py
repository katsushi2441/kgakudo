#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""こども家庭庁「放課後児童健全育成事業（放課後児童クラブ）の実施状況」のPDFを、機械で読める形に開く。

**なぜ要るか（2026-09-21 実測）**
- 公表は **PDFだけ**。Excel も CSV も無く、**e-Stat にも載っていない**
  （業務統計のページに「e-Statに掲載されていない」と明記され、全形式が「なし」）。
- 調査は市区町村単位で取っている（令和7年は1,633市区町村が実施）のに、表として出ているのは
  **都道府県47＋指定都市・中核市等（約82）** と、**待機児童が50人以上いる市町村（88）** だけ。
- 待機児童は全国 16,330 人。うち 50人以上の88市町村で 11,208 人。
  **残り 5,122 人（31%）は、どの市町村のものか国の公表からは分からない。**

ここでは PDF から3つの表を取り出して JSON/CSV にする。**推定はしない。**
載っていない市町村は「未公表」として、数字を作らない。

  /usr/bin/python3 scripts/parse_cfa.py data/raw/r7_2025_jittai.pdf --year 2025

出典: こども家庭庁「令和7年 放課後児童健全育成事業（放課後児童クラブ）の実施状況」
      （令和7年5月1日現在・令和7年12月23日公表）
"""
import argparse
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 表の切れ目に使う見出し。PDFの表は左右2段組みなので、1行に2件ぶん入る。
SECTIONS = {
    'clubs': '放課後児童クラブ数及び登録児童数',
    'waiting': '利用できなかった児童数（待機児童数）（都道府県・指定都市・中核市別',
    'waiting50': '待機児童）が５０人以上いる市町村',
}
# 段組みの左は都道府県(1〜47)、右は指定都市・中核市等。番号で見分ける。
PREF_MAX = 47


def text_of(pdf: str) -> list:
    out = subprocess.run(['pdftotext', '-layout', pdf, '-'], capture_output=True, check=True)
    return out.stdout.decode('utf-8', 'replace').split('\n')


def _num(s: str) -> int:
    s = s.replace(',', '').replace('△', '-').replace('▲', '-').strip()
    return int(s)


def parse_clubs(lines: list) -> list:
    """№ 名称 クラブ数 登録児童数 の表（都道府県・指定都市・中核市等）。"""
    i = max(k for k, l in enumerate(lines) if '放課後児童クラブ数及び登録児童数' in l)
    pat = re.compile(r'(\d{1,3})\s+([^\s\d]{1,8}?(?:[都道府県]|市))\s+([\d,]+)\s+([\d,]+)')
    rows, seen = [], set()
    for l in lines[i:i + 130]:
        for m in pat.finditer(l):
            no, name = int(m.group(1)), m.group(2)
            key = (no, name)
            if key in seen:
                continue
            seen.add(key)
            rows.append(dict(no=no, name=name, kind='都道府県' if no <= PREF_MAX else '指定都市・中核市等',
                             clubs=_num(m.group(3)), registered=_num(m.group(4))))
    return sorted(rows, key=lambda r: (r['kind'] != '都道府県', r['no']))


def parse_waiting(lines: list) -> list:
    """№ 名称 今年 前年 増減 の表（待機児童数・対前年）。"""
    i = max(k for k, l in enumerate(lines) if '利用できなかった児童数（待機児童数）（都道府県・指定都市・中核市別' in l)
    pat = re.compile(r'(\d{1,3})\s+([^\s\d]{1,8}?(?:[都道府県]|市))\s+([\d,]+)\s+([\d,]+)\s+(△?\s?[\d,]+)')
    rows, seen = [], set()
    for l in lines[i:i + 130]:
        for m in pat.finditer(l):
            no, name = int(m.group(1)), m.group(2)
            if (no, name) in seen:
                continue
            seen.add((no, name))
            rows.append(dict(no=no, name=name, kind='都道府県' if no <= PREF_MAX else '指定都市・中核市等',
                             waiting=_num(m.group(3)), waiting_prev=_num(m.group(4))))
    return sorted(rows, key=lambda r: (r['kind'] != '都道府県', r['no']))


def parse_waiting50(lines: list) -> list:
    """待機児童が50人以上いる市町村の一覧。**これだけが市区町村名で出ている。**"""
    i = max(k for k, l in enumerate(lines) if '５０人以上いる市町村' in l)
    pat = re.compile(r'(\d{1,3})\s+(\S+?[都道府県])\s+([^\s＊]+?[市区町村])\s*＊?\s+([\d,]+)')
    rows, seen = [], set()
    for l in lines[i:i + 140]:
        for m in pat.finditer(l):
            no = int(m.group(1))
            if no in seen:
                continue
            seen.add(no)
            rows.append(dict(no=no, pref=m.group(2), city=m.group(3), waiting=_num(m.group(4))))
    return sorted(rows, key=lambda r: r['no'])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('pdf')
    ap.add_argument('--year', type=int, required=True, help='調査年（5月1日現在の年）')
    a = ap.parse_args()
    lines = text_of(a.pdf)

    clubs = parse_clubs(lines)
    waiting = parse_waiting(lines)
    w50 = parse_waiting50(lines)

    by = {(r['no'], r['name']): r for r in clubs}
    for r in waiting:
        t = by.get((r['no'], r['name']))
        if t:
            t.update(waiting=r['waiting'], waiting_prev=r['waiting_prev'])

    out = dict(
        year=a.year,
        source='こども家庭庁「放課後児童健全育成事業（放課後児童クラブ）の実施状況」',
        source_url='https://www.cfa.go.jp/policies/kosodateshien/houkago-jidou',
        as_of=f'{a.year}-05-01',
        note='国の公表はPDFのみで、市区町村別に出ているのは待機児童が50人以上の市町村だけ。'
             'それ以外の市町村の数字は公表されていない（当社が推定することはしない）。',
        areas=clubs,
        waiting50=w50,
    )
    os.makedirs(os.path.join(ROOT, 'data'), exist_ok=True)
    p = os.path.join(ROOT, 'data', f'gakudo_{a.year}.json')
    with open(p, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)

    pref = [r for r in clubs if r['kind'] == '都道府県']
    city = [r for r in clubs if r['kind'] != '都道府県']
    print(f'都道府県 {len(pref)} / 指定都市・中核市等 {len(city)} / 待機50人以上の市町村 {len(w50)}')
    print('待機の合計（都道府県分）:', sum(r.get('waiting', 0) for r in pref))
    print('50人以上の市町村の合計:', sum(r['waiting'] for r in w50))
    print('→', p)
    miss = [r['name'] for r in clubs if 'waiting' not in r]
    if miss:
        print('待機の値が取れなかった:', miss[:10], f'({len(miss)}件)', file=sys.stderr)


if __name__ == '__main__':
    main()
