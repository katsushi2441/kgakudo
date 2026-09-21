#!/usr/bin/env python3
"""Kurage 学童保育ナビ PV を公開し、kappstore 商品ページに設置する（kpvgen ビルド完了後に実行。再実行可）。
  /usr/bin/python3 deploy_pv.py <pv.mp4> <poster.jpg>
1) https://kurage.exbridge.jp/pv/ に mp4 と poster を置く（他の PV と同じ場所）
2) kpv 台帳（kpv_data/videos.json）に id=kgakudo を登録（既存なら seconds を更新）
3) kappstore 台帳（kapp_data/apps.json）の id=f2853d368ddf8e57 に video_url / video_poster を入れる（file 名は触らない）
4) 商品ページに <video> が出ることを確認
"""
import datetime
import ftplib
import io
import json
import os
import subprocess
import sys
import time
import urllib.request

PV, POSTER = sys.argv[1], sys.argv[2]
BASE = "https://kurage.exbridge.jp/pv/"
VID = BASE + "gakudo-pv-41s.mp4"
POS = BASE + "gakudo-pv-poster.jpg"
PRODUCT_ID = "f2853d368ddf8e57"
PRODUCT_URL = f"https://kappstore.exbridge.jp/app.php?id={PRODUCT_ID}"
HERE = os.path.dirname(os.path.abspath(__file__))
SECS = int(round(float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", PV]).decode().strip())))


def env():
    for l in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in l and not l.startswith("#"):
            k, v = l.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main():
    env()
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=300)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    f.cwd("/web/kurage_exbridge_jp/pv")
    f.storbinary("STOR gakudo-pv-41s.mp4", open(PV, "rb"))
    f.storbinary("STOR gakudo-pv-poster.jpg", open(POSTER, "rb"))
    print(f"  pv/ に配置（{SECS}秒）")
    # kpv 台帳
    f.cwd("/web/kurage_exbridge_jp/kpv_data")
    b = io.BytesIO()
    f.retrbinary("RETR videos.json", b.write)
    k = json.loads(b.getvalue())
    vs = k["videos"] if isinstance(k, dict) else k
    # **題名・タグは毎回上書きする。** 前の製品のスクリプトを写して PRODUCT_ID だけ直したため、
    # /kpv/chinjo の題名と description が takken のまま公開されていた（2026-09-19にユーザー指摘）。
    # 追加のときだけ値を入れる作りだと、直しても再実行で反映されない。
    TITLE = ("学童保育の待機児童を住所で調べる — Kurage 学童保育ナビ。"
             "保育園の待機も同じ住所で。国の公表は88市町村だけ（オンプレミス55,000円・PHP1ファイル）")
    TAGS = ["gakudo", "gakudohoiku", "taikijido", "hoikuen", "kosodate", "opendata", "kappstore"]
    hit = [v for v in vs if v.get("id") == "gakudo"]
    if not hit:
        vs.append({"id": "gakudo", "title": TITLE,
                   "video": VID, "poster": POS, "page": PRODUCT_URL, "seconds": SECS,
                   "tags": TAGS, "hidden": 0,
                   "created_at": datetime.datetime.now().isoformat()})
        f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
        print("  kpv 台帳: gakudo 追加")
    else:
        v = hit[0]
        want = {"title": TITLE, "tags": TAGS, "seconds": SECS,
                "video": VID, "poster": POS, "page": PRODUCT_URL}
        diff = [x for x, y in want.items() if v.get(x) != y]
        if diff:
            v.update(want)
            f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
            print("  kpv 台帳: 更新 →", "・".join(diff))
        else:
            print("  kpv 台帳: 登録済み")
    # kappstore 台帳（必ず退避してから、対象1件の video 欄だけ触る）
    f.cwd("/web/kappstore_exbridge_jp/kapp_data")
    b = io.BytesIO()
    f.retrbinary("RETR apps.json", b.write)
    raw = b.getvalue()
    open(os.path.join(HERE, f"apps_backup_{datetime.datetime.now():%Y%m%d_%H%M%S}.json"), "wb").write(raw)
    d = json.loads(raw)
    n_before = len(d["apps"])
    hit = [a for a in d["apps"] if a.get("id") == PRODUCT_ID]
    if not hit:
        raise SystemExit("kappstore 台帳に商品が見つかりません: " + PRODUCT_ID)
    a = hit[0]
    if a.get("video_url") != VID or a.get("video_poster") != POS:
        a["video_url"], a["video_poster"], a["updated_at"] = VID, POS, int(time.time())
        assert len(d["apps"]) == n_before
        f.storbinary("STOR apps.json", io.BytesIO(json.dumps(d, ensure_ascii=False).encode("utf-8")))
        print(f"  kappstore 台帳: video_url 設定（{n_before}件のまま）")
    else:
        print("  kappstore 台帳: 設定済み")
    f.quit()
    for u in (VID, POS):
        r = urllib.request.urlopen(urllib.request.Request(u, method="HEAD"), timeout=60)
        print(f"  {r.status} {r.headers.get('Content-Type')} {r.headers.get('Content-Length')}B {u}")
    html = urllib.request.urlopen(PRODUCT_URL, timeout=60).read().decode("utf-8", "replace")
    print("  商品ページ <video>:", "<video" in html and "gakudo-pv-41s.mp4" in html)


if __name__ == "__main__":
    main()
