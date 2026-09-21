#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""JSON を CSV にする。**国が出していない形**（機械で読める形）で配るためのもの。
  /usr/bin/python3 scripts/to_csv.py --year 2025
"""
import argparse, csv, json, os
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ap = argparse.ArgumentParser(); ap.add_argument('--year', type=int, default=2025); a = ap.parse_args()
d = json.load(open(os.path.join(ROOT, 'data', f'gakudo_{a.year}.json'), encoding='utf-8'))
out = os.path.join(ROOT, 'data')
with open(os.path.join(out, f'gakudo_areas_{a.year}.csv'), 'w', encoding='utf-8-sig', newline='') as f:
    w = csv.writer(f); w.writerow(['区分', '№', '名称', 'クラブ数', '登録児童数', '待機児童数', '前年の待機児童数', '増減'])
    for r in d['areas']:
        w.writerow([r['kind'], r['no'], r['name'], r['clubs'], r['registered'],
                    r.get('waiting', ''), r.get('waiting_prev', ''),
                    (r['waiting'] - r['waiting_prev']) if 'waiting' in r and 'waiting_prev' in r else ''])
with open(os.path.join(out, f'gakudo_waiting50_{a.year}.csv'), 'w', encoding='utf-8-sig', newline='') as f:
    w = csv.writer(f); w.writerow(['順位', '都道府県', '市区町村', '待機児童数'])
    for r in d['waiting50']:
        w.writerow([r['no'], r['pref'], r['city'], r['waiting']])
print('書き出し:', out)
