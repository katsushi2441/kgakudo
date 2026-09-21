#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""kappstore の商品画像 1200×630。ライトテーマ（白＋ティール＋濃紺）＋マスコット。
数字は成長するものを焼き込まない（[[feedback_banner_variety]]）。"""
import os
from PIL import Image, ImageDraw, ImageFont
W, H = 1200, 630
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'outputs', 'kgakudo_store_1200x630.png')
MASCOT = '/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png'
FB = '/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc'
FM = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'
FR = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'
img = Image.new('RGB', (W, H), '#ffffff')
dr = ImageDraw.Draw(img, 'RGBA')
dr.ellipse([-180, -240, 480, 380], fill=(230, 244, 242, 255))
dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(240, 246, 246, 255))
mascot = None
if os.path.exists(MASCOT):
    mascot = Image.open(MASCOT).convert('RGBA')
    mh = 280
    mascot = mascot.resize((int(mascot.width * mh / mascot.height), mh))
cx = 520 if mascot else W // 2
f_badge = ImageFont.truetype(FM, 25)
f_h = ImageFont.truetype(FB, 48)
f_s = ImageFont.truetype(FR, 24)
f_band = ImageFont.truetype(FM, 28)
badge = 'こども家庭庁の全国調査から'
bw = dr.textlength(badge, font=f_badge) + 44
dr.rounded_rectangle([cx - bw / 2, 84, cx + bw / 2, 133], radius=24, fill='#e6f4f2', outline='#bfe3de')
dr.text((cx, 108), badge, font=f_badge, fill='#0a726b', anchor='mm')
dr.text((cx, 200), '学童と保育園の待機を、', font=f_h, fill='#12202f', anchor='mm')
dr.text((cx, 273), '住所ひとつで。', font=f_h, fill='#0a9a8f', anchor='mm')
dr.text((cx, 345), '学童はPDFだけ、保育所はExcel。同じ役所でも公表が違います。', font=f_s, fill='#5d6b7a', anchor='mm')
dr.text((cx, 385), '分からない場所は「未公表」と書きます。推定はしません。', font=f_s, fill='#5d6b7a', anchor='mm')
bt = 'PHP 1ファイル＋JSON ／ サーバー不要'
bw2 = dr.textlength(bt, font=f_band) / 2 + 34
dr.rounded_rectangle([cx - bw2, 448, cx + bw2, 506], radius=15, fill='#0a9a8f')
dr.text((cx, 477), bt, font=f_band, fill='#ffffff', anchor='mm')
if mascot:
    img.paste(mascot, (W - mascot.width - 40, H - mascot.height - 30), mascot)
dr.text((40, H - 38), 'kurage.exbridge.jp/kgakudo.php/', font=ImageFont.truetype(FR, 21), fill='#5d6b7a', anchor='lm')
img.save(OUT, optimize=True)
print(OUT, img.size)
