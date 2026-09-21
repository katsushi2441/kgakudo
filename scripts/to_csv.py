#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""JSON を CSV にする。**国が出していない形**（機械で読める形）で配るためのもの。
  /usr/bin/python3 scripts/to_csv.py --year 2025
"""
import argparse, csv, json, os
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ap = argparse.ArgumentParser(); ap.add_argument('--year', type=int, default=2025); ap.add_argument('--hoiku', action='store_true'); a = ap.parse_args()
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

# 保育所ぶんもCSVにする（--hoiku）
if a.hoiku:
    import json as _j, csv as _c, os as _o
    _d = _j.load(open(_o.path.join(ROOT, 'data', f'hoiku_{a.year}.json'), encoding='utf-8'))
    with open(_o.path.join(ROOT, 'data', f'hoiku_municipalities_{a.year}.csv'), 'w', encoding='utf-8-sig', newline='') as f:
        w = _c.writer(f)
        w.writerow(['都道府県', '市区町村', '保育所の定員', '認定こども園の定員', '申込者数', '待機児童数', '前年の待機児童数'])
        for r in _d['municipalities']:
            w.writerow([r['pref'], r['city'], r.get('capacity_hoikusho', ''), r.get('capacity_kodomoen', ''),
                        r.get('applicants', ''), '' if r.get('waiting') is None else r['waiting'],
                        '' if r.get('waiting_prev') is None else r['waiting_prev']])
    print('保育所CSVも書き出しました')
